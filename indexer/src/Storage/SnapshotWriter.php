<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Storage;

use BitrixMcp\Indexer\Core\BuildVersion;
use BitrixMcp\Indexer\Core\IndexRoot;
use BitrixMcp\Indexer\Core\ModuleVersion;
use BitrixMcp\Indexer\Core\ParseErrorCollector;
use PDO;
use PDOStatement;

/**
 * Запись корней, модулей, файлов и итогового снимка.
 *
 * Порядок записи не произволен: корни и модули пишутся ДО первого файла,
 * а файлы — до первой извлечённой сущности. Так внешний ключ `module_id`
 * всегда существует к моменту, когда появляется факт, который надо
 * атрибутировать (Принцип II).
 */
final class SnapshotWriter
{
    private ?PDOStatement $insertFile = null;
    private ?PDOStatement $insertParseError = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $indexerVersion = '0.1.0',
    ) {
    }

    public function writeRoot(IndexRoot $root): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO index_root (path, label, is_core) VALUES (:path, :label, :is_core)'
        );
        $stmt->execute([
            ':path' => $root->path,
            ':label' => $root->label,
            ':is_core' => $root->isCore ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    /**
     * @param array<string, ModuleVersion> $modules
     * @return array<string, int> код модуля => module_id
     */
    public function writeModules(int $rootId, array $modules): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO module (root_id, code, version, version_date, version_source, rel_path)
             VALUES (:root_id, :code, :version, :version_date, :version_source, :rel_path)'
        );

        $ids = [];

        foreach ($modules as $code => $module) {
            $stmt->execute([
                ':root_id' => $rootId,
                ':code' => $code,
                // NULL, а не подставленная версия сборки: неизвестность обязана
                // остаться отличимой от знания (Принцип II).
                ':version' => $module->version,
                ':version_date' => $module->versionDate,
                ':version_source' => $module->source->value,
                ':rel_path' => $module->relPath,
            ]);

            $ids[$code] = (int)$this->pdo->lastInsertId();
        }

        return $ids;
    }

    public function writeFile(
        int $rootId,
        int $moduleId,
        string $relPath,
        int $size,
        int $mtime,
        string $sha256,
        bool $parsedOk,
        bool $hadShortOpenTag,
    ): int {
        $this->insertFile ??= $this->pdo->prepare(
            'INSERT INTO file
                (root_id, module_id, rel_path, size, mtime, sha256, parsed_ok, had_short_open_tag, seen_in_pass)
             VALUES
                (:root_id, :module_id, :rel_path, :size, :mtime, :sha256, :parsed_ok, :short_tag, 1)'
        );

        $this->insertFile->execute([
            ':root_id' => $rootId,
            ':module_id' => $moduleId,
            ':rel_path' => $relPath,
            ':size' => $size,
            ':mtime' => $mtime,
            ':sha256' => $sha256,
            ':parsed_ok' => $parsedOk ? 1 : 0,
            ':short_tag' => $hadShortOpenTag ? 1 : 0,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function writeParseError(int $fileId, string $message, ?int $line): void
    {
        $this->insertParseError ??= $this->pdo->prepare(
            'INSERT INTO parse_error (file_id, message, line) VALUES (:file_id, :message, :line)'
        );

        $this->insertParseError->execute([
            ':file_id' => $fileId,
            ':message' => $message,
            ':line' => $line,
        ]);
    }

    /**
     * Фиксация снимка.
     *
     * `parse_error_count` берётся ИЗ ТАБЛИЦЫ, а не из накопителя в памяти.
     * Разница принципиальна для инкрементального прогона: там накопитель
     * содержит ошибки только текущего прохода, и при отсутствии изменений
     * он пуст. Запись его значения обнуляла бы счётчик, хотя непарсибельные
     * файлы никуда не делись, — отчёт сообщал бы «ошибок нет» при живой
     * ошибке в индексе. Принцип V запрещает проглатывать ошибки, а тихо
     * обнулённый счётчик — это ровно проглатывание.
     *
     * Накопитель остаётся аргументом: он нужен вызывающему для вывода
     * примеров и проверки порога, но источником истины является таблица.
     */
    public function finalize(
        ?BuildVersion $build,
        int $fileCount,
        int $totalBytes,
        ParseErrorCollector $errors,
        bool $shortOpenTagEnabled,
    ): void {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM parse_error');
        $storedErrors = $stmt === false ? $errors->count() : (int)($stmt->fetchColumn() ?: 0);

        $stmt = $this->pdo->prepare(
            'INSERT OR REPLACE INTO snapshot
                (id, sm_version, sm_version_date, indexed_at, indexer_version, schema_version,
                 file_count, total_bytes, parse_error_count, short_open_tag_enabled,
                 docs_crawled_at, docs_source, docs_source_revision)
             VALUES
                (1, :sm, :sm_date, :at, :iv, :sv, :fc, :tb, :pe, :sot,
                 (SELECT docs_crawled_at FROM snapshot WHERE id = 1),
                 (SELECT docs_source FROM snapshot WHERE id = 1),
                 (SELECT docs_source_revision FROM snapshot WHERE id = 1))'
        );

        $stmt->execute([
            ':sm' => $build?->smVersion,
            ':sm_date' => $build?->smVersionDate,
            ':at' => gmdate('c'),
            ':iv' => $this->indexerVersion,
            ':sv' => Schema::VERSION,
            ':fc' => $fileCount,
            ':tb' => $totalBytes,
            ':pe' => $storedErrors,
            ':sot' => $shortOpenTagEnabled ? 1 : 0,
        ]);
    }
}
