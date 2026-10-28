<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Command;

use BitrixMcp\Indexer\Core\Config;
use BitrixMcp\Indexer\Core\FileWalker;
use BitrixMcp\Indexer\Core\ModuleVersionReader;
use BitrixMcp\Indexer\Core\ParseErrorCollector;
use BitrixMcp\Indexer\Core\WalkedFile;
use BitrixMcp\Indexer\ExitCode;
use BitrixMcp\Indexer\Extract\FileExtractor;
use BitrixMcp\Indexer\Preflight\PreflightFailure;
use BitrixMcp\Indexer\Preflight\ShortOpenTagGuard;
use BitrixMcp\Indexer\Storage\Database;
use BitrixMcp\Indexer\Storage\SchemaVersionGuard;
use BitrixMcp\Indexer\Storage\SnapshotWriter;
use BitrixMcp\Indexer\Storage\SymbolWriter;
use PDO;

/**
 * Инкрементальная переиндексация.
 *
 * Стратегия из R-010: `(размер, mtime)` как быстрый фильтр, SHA-256 как арбитр.
 * Совпал быстрый фильтр — файл не читается вообще. Разошёлся — файл читается
 * и сверяется хеш; перестройка только при отличии содержимого. Это защищает
 * от лишней работы при обновлениях, меняющих время модификации без изменения
 * файла, — обычное дело для распаковки архива обновления.
 *
 * Карта версий модулей перечитывается ЦЕЛИКОМ и всегда: она дёшева
 * (93 файла) и критична для Принципа II. Оптимизировать её через
 * инкрементальность нельзя — устаревшая версия хуже отсутствующей.
 */
final class UpdateCommand
{
    public function __construct(
        private readonly ShortOpenTagGuard $guard = new ShortOpenTagGuard(),
    ) {
    }

    /** @param array<string, string> $cli */
    public function run(array $cli, Reporter $out): ExitCode
    {
        $this->guard->enforce();

        $config = Config::resolve($cli);

        if (!is_file($config->indexPath)) {
            throw new PreflightFailure(
                "ОШИБКА: индекс не найден: {$config->indexPath}\n"
                . 'Инкрементальное обновление требует существующего индекса. Постройте его: make build',
                ExitCode::ConfigError,
            );
        }

        $started = microtime(true);
        $pdo = Database::open($config->indexPath);
        SchemaVersionGuard::enforce($pdo, $config->indexPath);

        $versions = new ModuleVersionReader();
        $walker = new FileWalker();
        $extractor = new FileExtractor();
        $errors = new ParseErrorCollector();
        $snapshots = new SnapshotWriter($pdo);
        $symbols = new SymbolWriter($pdo);

        $out->line('Инкрементальная переиндексация');
        $out->line('  индекс: ' . $config->indexPath);
        $out->line('');

        // Шаг 1. Отметить все файлы как невстреченные. Кто останется
        // невстреченным после обхода — удалён из ядра.
        $pdo->exec('UPDATE file SET seen_in_pass = 0');

        // Шаг 2. Перечитать версии целиком.
        $build = $versions->readBuildVersion($config->coreRoot());
        $rootIds = $this->rootIds($pdo);
        $moduleIds = [];

        $pdo->beginTransaction();

        foreach ($config->roots as $root) {
            $rootId = $rootIds[$root->path] ?? $snapshots->writeRoot($root);
            $rootIds[$root->path] = $rootId;
            $moduleIds[$root->path] = $this->syncModules($pdo, $rootId, $versions->readAll($root));
        }

        $pdo->commit();

        $out->line(
            $build === null
                ? 'ВНИМАНИЕ: SM_VERSION не прочитан'
                : "Сборка: SM_VERSION {$build->smVersion}"
        );

        // Шаг 3. Обход со сверкой.
        $existing = $this->existingFiles($pdo);

        $unchanged = 0;
        $changed = 0;
        $added = 0;
        $fileCount = 0;
        $totalBytes = 0;

        $pdo->beginTransaction();

        foreach ($walker->walkAll($config->roots) as $file) {
            $rootId = $rootIds[$file->root->path] ?? null;
            $moduleId = $moduleIds[$file->root->path][$file->moduleCode] ?? null;

            if ($rootId === null || $moduleId === null) {
                continue;
            }

            $fileCount++;
            $totalBytes += $file->size;

            $key = $rootId . "\0" . $file->relPath;
            $known = $existing[$key] ?? null;

            if ($known !== null && $known['size'] === $file->size && $known['mtime'] === $file->mtime) {
                // Быстрый фильтр совпал — файл даже не читаем.
                $pdo->exec('UPDATE file SET seen_in_pass = 1 WHERE id = ' . (int)$known['id']);
                $unchanged++;
                continue;
            }

            $code = $file->readContents();

            if ($code === null) {
                $errors->record($file->relPath, 'файл недоступен для чтения');
                continue;
            }

            $sha = WalkedFile::sha256($code);

            if ($known !== null && $known['sha256'] === $sha) {
                // Содержимое то же, изменилось только время модификации —
                // обычное дело после распаковки обновления. Перестраивать нечего.
                $stmt = $pdo->prepare('UPDATE file SET mtime = ?, size = ?, seen_in_pass = 1 WHERE id = ?');
                $stmt->execute([$file->mtime, $file->size, $known['id']]);
                $unchanged++;
                continue;
            }

            // Удаление строки файла каскадом убирает все его символы, события
            // и ORM-записи. Одна операция вместо обхода зависимых таблиц (R-010).
            if ($known !== null) {
                $del = $pdo->prepare('DELETE FROM file WHERE id = ?');
                $del->execute([$known['id']]);
                $changed++;
            } else {
                $added++;
            }

            $this->indexFile($file, $rootId, $moduleId, $code, $sha, $extractor, $snapshots, $symbols, $errors);

            if (($changed + $added) % 500 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
                $out->progress(sprintf('  изменено %d, добавлено %d', $changed, $added));
            }
        }

        $pdo->commit();
        $out->progressDone();

        // Шаг 4. Удалить исчезнувшие.
        $removed = $this->deleteOrphans($pdo);

        $snapshots->finalize($build, $fileCount, $totalBytes, $errors, $this->guard->isEnabled());

        $elapsed = microtime(true) - $started;

        $out->line('');
        $out->line(sprintf(
            'изменено: %d, добавлено: %d, удалено: %d, без изменений: %d',
            $changed,
            $added,
            $removed,
            $unchanged,
        ));
        $out->line(sprintf('Время: %.1f с', $elapsed));

        if ($errors->count() > 0) {
            $out->line(sprintf('Ошибок разбора: %d', $errors->count()));
        }

        return ExitCode::Success;
    }

    private function indexFile(
        WalkedFile $file,
        int $rootId,
        int $moduleId,
        string $code,
        string $sha,
        FileExtractor $extractor,
        SnapshotWriter $snapshots,
        SymbolWriter $symbols,
        ParseErrorCollector $errors,
    ): void {
        [$extraction, $error, $line] = $extractor->extract($code, $file->relPath);

        $fileId = $snapshots->writeFile(
            $rootId,
            $moduleId,
            $file->relPath,
            $file->size,
            $file->mtime,
            $sha,
            $extraction !== null,
            WalkedFile::hasShortOpenTag($code),
        );

        if ($extraction === null) {
            $errors->record($file->relPath, $error ?? 'неизвестная ошибка разбора', $line);
            $snapshots->writeParseError($fileId, $error ?? 'неизвестная ошибка разбора', $line);

            return;
        }

        if (!$extraction->isEmpty()) {
            $symbols->write($extraction, $moduleId, $fileId);
        }
    }

    /** @return array<string, int> */
    private function rootIds(PDO $pdo): array
    {
        $result = [];
        $stmt = $pdo->query('SELECT id, path FROM index_root');

        if ($stmt === false) {
            return [];
        }

        /** @var array{id: int, path: string} $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[$row['path']] = (int)$row['id'];
        }

        return $result;
    }

    /**
     * @param array<string, \BitrixMcp\Indexer\Core\ModuleVersion> $map
     * @return array<string, int>
     */
    private function syncModules(PDO $pdo, int $rootId, array $map): array
    {
        $update = $pdo->prepare(
            'UPDATE module SET version = ?, version_date = ?, version_source = ?, rel_path = ?
             WHERE root_id = ? AND code = ?'
        );
        $insert = $pdo->prepare(
            'INSERT INTO module (root_id, code, version, version_date, version_source, rel_path)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $select = $pdo->prepare('SELECT id FROM module WHERE root_id = ? AND code = ?');

        $ids = [];

        foreach ($map as $code => $module) {
            $select->execute([$rootId, $code]);
            $existing = $select->fetchColumn();

            if ($existing === false) {
                $insert->execute([
                    $rootId, $code, $module->version, $module->versionDate,
                    $module->source->value, $module->relPath,
                ]);
                $ids[$code] = (int)$pdo->lastInsertId();
                continue;
            }

            $update->execute([
                $module->version, $module->versionDate, $module->source->value,
                $module->relPath, $rootId, $code,
            ]);
            $ids[$code] = (int)$existing;
        }

        return $ids;
    }

    /** @return array<string, array{id: int, size: int, mtime: int, sha256: string}> */
    private function existingFiles(PDO $pdo): array
    {
        $result = [];
        $stmt = $pdo->query('SELECT id, root_id, rel_path, size, mtime, sha256 FROM file');

        if ($stmt === false) {
            return [];
        }

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            /** @var array{id: int, root_id: int, rel_path: string, size: int, mtime: int, sha256: string} $row */
            $key = $row['root_id'] . "\0" . $row['rel_path'];
            $result[$key] = [
                'id' => (int)$row['id'],
                'size' => (int)$row['size'],
                'mtime' => (int)$row['mtime'],
                'sha256' => (string)$row['sha256'],
            ];
        }

        return $result;
    }

    private function deleteOrphans(PDO $pdo): int
    {
        $stmt = $pdo->query('SELECT COUNT(*) FROM file WHERE seen_in_pass = 0');
        $count = $stmt === false ? 0 : (int)($stmt->fetchColumn() ?: 0);

        if ($count > 0) {
            $pdo->exec('DELETE FROM file WHERE seen_in_pass = 0');
        }

        return $count;
    }
}
