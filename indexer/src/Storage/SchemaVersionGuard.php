<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Storage;

use BitrixMcp\Indexer\ExitCode;
use BitrixMcp\Indexer\Preflight\PreflightFailure;
use PDO;
use Throwable;

/**
 * Проверка совместимости версии схемы.
 *
 * Отказ, а не попытка прочитать «как получится»: индекс чужой версии может быть
 * структурно похож и при этом означать другое. Неверно истолкованные данные хуже
 * отсутствующих, потому что выглядят достоверными.
 */
final class SchemaVersionGuard
{
    /**
     * @throws PreflightFailure если версия схемы несовместима или индекс нечитаем
     */
    public static function enforce(PDO $pdo, string $indexPath): void
    {
        $actual = self::read($pdo);

        if ($actual === null) {
            throw new PreflightFailure(
                "ОШИБКА: индекс {$indexPath} не содержит снимка.\n"
                . 'Постройте его заново: make build',
                ExitCode::SchemaMismatch,
            );
        }

        if ($actual !== Schema::VERSION) {
            throw new PreflightFailure(
                sprintf(
                    "ОШИБКА: несовместимая версия схемы индекса.\n"
                    . "  файл %s: версия %d\n"
                    . "  ожидается: версия %d\n\n"
                    . 'Пересоберите индекс: make build',
                    $indexPath,
                    $actual,
                    Schema::VERSION,
                ),
                ExitCode::SchemaMismatch,
            );
        }
    }

    public static function read(PDO $pdo): ?int
    {
        try {
            $stmt = $pdo->query('SELECT schema_version FROM snapshot WHERE id = 1');

            if ($stmt === false) {
                return null;
            }

            $value = $stmt->fetchColumn();
        } catch (Throwable) {
            return null;
        }

        return is_numeric($value) ? (int)$value : null;
    }
}
