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
use BitrixMcp\Indexer\Preflight\ShortOpenTagGuard;
use BitrixMcp\Indexer\Storage\Database;
use BitrixMcp\Indexer\Storage\DocsPreserver;
use BitrixMcp\Indexer\Storage\Schema;
use BitrixMcp\Indexer\Storage\SnapshotWriter;
use BitrixMcp\Indexer\Storage\SymbolWriter;

/**
 * Полная индексация.
 *
 * Порядок шагов содержателен, а не организационен:
 *   preflight → версия сборки → карта версий модулей → файлы → сущности → индексы
 *
 * Карта версий читается ДО обхода, потому что без неё ни одна извлечённая
 * сущность не может быть корректно атрибутирована (Принцип II). Индексы
 * создаются ПОСЛЕ массовой вставки: построение B-tree на лету замедляет
 * загрузку в разы.
 */
final class BuildCommand
{
    private const PARSE_ERROR_THRESHOLD = 0.01;

    public function __construct(
        private readonly ShortOpenTagGuard $guard = new ShortOpenTagGuard(),
    ) {
    }

    /** @param array<string, string> $cli */
    public function run(array $cli, Reporter $out): ExitCode
    {
        // До открытия БД и до любого обхода: индекс, построенный при выключенной
        // настройке, недостоверен, и создавать файл незачем (R-001).
        $this->guard->enforce();

        $config = Config::resolve($cli);
        $started = microtime(true);

        $out->line('Индексация');
        foreach ($config->roots as $root) {
            $out->line(sprintf('  корень %-8s %s', $root->label, $root->path));
        }
        $out->line('  индекс          ' . $config->indexPath);
        $out->line('');

        // Документация переносится через пересоздание индекса: она живёт
        // на стороннем сайте и от версии ядра не зависит. Без переноса
        // пересборка кода уничтожала бы её вместе с кодом.
        $docs = new DocsPreserver();
        $docs->capture($config->indexPath);

        $pdo = Database::recreate($config->indexPath);

        if ($docs->count() > 0) {
            $docs->restore($pdo);
            $out->line(sprintf('Документация сохранена при пересборке: %d страниц', $docs->count()));
        }
        $versions = new ModuleVersionReader();
        $walker = new FileWalker();
        $extractor = new FileExtractor();
        $errors = new ParseErrorCollector();
        $snapshots = new SnapshotWriter($pdo);
        $symbols = new SymbolWriter($pdo);

        $build = $versions->readBuildVersion($config->coreRoot());

        if ($build === null) {
            $out->line('ВНИМАНИЕ: не удалось прочитать SM_VERSION. Снимок останется без версии сборки.');
        } else {
            $out->line("Сборка: SM_VERSION {$build->smVersion} от {$build->smVersionDate}");
        }

        $moduleIds = [];
        $rootIds = [];
        $modulesWithoutVersion = [];

        $pdo->beginTransaction();

        foreach ($config->roots as $root) {
            $rootId = $snapshots->writeRoot($root);
            $rootIds[$root->path] = $rootId;

            $map = $versions->readAll($root);
            $moduleIds[$root->path] = $snapshots->writeModules($rootId, $map);

            foreach ($map as $code => $module) {
                if (!$module->isKnown()) {
                    $modulesWithoutVersion[] = $code;
                }
            }

            $out->line(sprintf(
                'Модулей в %s: %d (без версии: %d)',
                $root->label,
                count($map),
                count(array_filter($map, static fn($m) => !$m->isKnown())),
            ));
        }

        $pdo->commit();

        $out->line('');

        $fileCount = 0;
        $totalBytes = 0;
        $shortTagFiles = 0;

        $pdo->beginTransaction();

        foreach ($walker->walkAll($config->roots) as $file) {
            $moduleId = $moduleIds[$file->root->path][$file->moduleCode] ?? null;

            if ($moduleId === null) {
                // Файл в каталоге, для которого не создана запись модуля.
                // Пропускаем осознанно: запись без атрибуции запрещена (Принцип II).
                continue;
            }

            $fileId = $this->processFile(
                $file,
                $rootIds[$file->root->path],
                $moduleId,
                $extractor,
                $snapshots,
                $symbols,
                $errors,
                $shortTagFiles,
            );

            $fileCount++;
            $totalBytes += $file->size;

            if ($fileCount % 5000 === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
                $out->progress(sprintf('  обработано %d файлов', $fileCount));
            }

            unset($fileId);
        }

        $pdo->commit();

        $out->progressDone();
        $out->line('');
        $out->line('Создание индексов...');
        Schema::createIndexes($pdo);

        $out->line("Полнотекстовый индекс по коду...");
        Schema::buildCodeIndex($pdo);

        $snapshots->finalize($build, $fileCount, $totalBytes, $errors, $this->guard->isEnabled());

        if ($docs->crawledAt() !== null) {
            $restore = $pdo->prepare(
                'UPDATE snapshot
                 SET docs_crawled_at = ?, docs_source = ?, docs_source_revision = ?
                 WHERE id = 1'
            );
            $restore->execute([$docs->crawledAt(), $docs->source(), $docs->revision()]);
        }

        $counts = $symbols->counts();
        $elapsed = microtime(true) - $started;

        $this->report(
            $out,
            $config,
            $fileCount,
            $totalBytes,
            $counts,
            $errors,
            $shortTagFiles,
            $elapsed,
            $modulesWithoutVersion,
            $walker->skippedVendorFiles(),
        );

        if ($errors->exceedsThreshold($fileCount, self::PARSE_ERROR_THRESHOLD)) {
            $out->line('');
            $out->line(sprintf(
                'ОШИБКА: доля непарсибельных файлов %.2f%% превышает порог %.0f%% (SC-009).',
                $errors->share($fileCount) * 100,
                self::PARSE_ERROR_THRESHOLD * 100,
            ));

            return ExitCode::ParseErrorThreshold;
        }

        return ExitCode::Success;
    }

    private function processFile(
        WalkedFile $file,
        int $rootId,
        int $moduleId,
        FileExtractor $extractor,
        SnapshotWriter $snapshots,
        SymbolWriter $symbols,
        ParseErrorCollector $errors,
        int &$shortTagFiles,
    ): int {
        $code = $file->readContents();

        if ($code === null) {
            $fileId = $snapshots->writeFile(
                $rootId,
                $moduleId,
                $file->relPath,
                $file->size,
                $file->mtime,
                '',
                false,
                false,
            );
            $errors->record($file->relPath, 'файл недоступен для чтения');
            $snapshots->writeParseError($fileId, 'файл недоступен для чтения', null);

            return $fileId;
        }

        $hadShortTag = WalkedFile::hasShortOpenTag($code);

        if ($hadShortTag) {
            $shortTagFiles++;
        }

        [$extraction, $error, $line] = $extractor->extract($code, $file->relPath);

        $fileId = $snapshots->writeFile(
            $rootId,
            $moduleId,
            $file->relPath,
            $file->size,
            $file->mtime,
            WalkedFile::sha256($code),
            $extraction !== null,
            $hadShortTag,
        );

        if ($extraction === null) {
            // Ошибка одного файла не прерывает проход, но и не исчезает:
            // молчаливый пропуск сделал бы отсутствие символа в индексе
            // неотличимым от его отсутствия в ядре (Принцип V).
            $errors->record($file->relPath, $error ?? 'неизвестная ошибка разбора', $line);
            $snapshots->writeParseError($fileId, $error ?? 'неизвестная ошибка разбора', $line);

            return $fileId;
        }

        if (!$extraction->isEmpty()) {
            $symbols->write($extraction, $moduleId, $fileId);
        }

        return $fileId;
    }

    /**
     * @param array{
     *   symbols: int, methods: int, functions: int, constants: int, usages: int,
     *   aliases: int, orm_entities: int, orm_fields: int, events: int,
     *   rest_methods: int, components: int
     * } $counts
     * @param list<string> $modulesWithoutVersion
     */
    private function report(
        Reporter $out,
        Config $config,
        int $fileCount,
        int $totalBytes,
        array $counts,
        ParseErrorCollector $errors,
        int $shortTagFiles,
        float $elapsed,
        array $modulesWithoutVersion,
        int $skippedVendor,
    ): void {
        $out->line('');
        $out->line('── Итог ──────────────────────────────────────────');
        $out->line(sprintf('Файлов:          %d (%.1f МБ)', $fileCount, $totalBytes / 1048576));

        // Сужение охвата обязано быть видимым: молчаливое исключение
        // читается как «обошли всё».
        if ($skippedVendor > 0) {
            $out->line(sprintf('  пропущено composer-зависимостей: %d файлов', $skippedVendor));
        }

        $out->line(sprintf('  с коротким тегом: %d', $shortTagFiles));
        $out->line(sprintf('Символов:        %d', $counts['symbols']));
        $out->line(sprintf('Методов:         %d', $counts['methods']));
        $out->line(sprintf('Функций:        %d', $counts['functions']));
        $out->line(sprintf('Констант классов: %d', $counts['constants']));
        $out->line(sprintf('Точек вызова:    %d', $counts['usages']));
        $out->line(sprintf('Псевдонимов:     %d', $counts['aliases']));
        $out->line(sprintf('ORM-сущностей:   %d (полей: %d)', $counts['orm_entities'], $counts['orm_fields']));
        $out->line(sprintf('Событий:         %d', $counts['events']));
        $out->line(sprintf('Компонентов:     %d', $counts['components']));
        $out->line(sprintf('REST-методов:    %d', $counts['rest_methods']));
        $out->line(sprintf(
            'Ошибок разбора:  %d (%.3f%%)',
            $errors->count(),
            $errors->share($fileCount) * 100,
        ));

        if ($modulesWithoutVersion !== []) {
            $out->line(sprintf(
                'Модулей без версии: %d (%s)',
                count($modulesWithoutVersion),
                implode(', ', array_slice($modulesWithoutVersion, 0, 10)),
            ));
        }

        foreach ($errors->sample() as $e) {
            $out->line(sprintf('  - %s: %s', $e['path'], $e['message']));
        }

        $size = is_file($config->indexPath) ? filesize($config->indexPath) : 0;

        $out->line(sprintf('Время:           %.1f с', $elapsed));
        $out->line(sprintf('Индекс:          %s (%.1f МБ)', $config->indexPath, ($size ?: 0) / 1048576));
    }
}
