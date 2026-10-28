<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Integration;

use BitrixMcp\Indexer\ExitCode;
use PDO;

/**
 * Принцип V: одинаковое состояние ядра обязано давать одинаковый индекс.
 *
 * Детерминированность нужна не ради красоты: без неё расхождение между двумя
 * прогонами неотличимо от изменения в ядре, и диагностика становится
 * невозможной.
 */
final class DeterminismTest extends IndexBuildTestCase
{
    public function testTwoBuildsProduceIdenticalContent(): void
    {
        $secondPath = sys_get_temp_dir() . '/bitrix-mcp-test-2-' . getmypid() . '.sqlite';

        try {
            self::assertSame(ExitCode::Success, $this->buildIndex($secondPath));

            $second = new PDO('sqlite:' . $secondPath);
            $second->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Снимок сравнивать нельзя целиком: indexed_at меняется по определению.
            $tables = [
                'module', 'symbol', 'method', 'parameter', 'symbol_alias', 'symbol_interface',
                'global_function', 'class_constant', 'usage_reference',
            ];

            foreach ($tables as $table) {
                $a = $this->digest($this->pdo, $table);
                $b = $this->digest($second, $table);

                self::assertSame($a, $b, "Содержимое таблицы {$table} различается между прогонами");
            }
        } finally {
            foreach ([$secondPath, $secondPath . '-wal', $secondPath . '-shm'] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
        }
    }

    public function testModuleVersionsAreAttachedToEverySymbol(): void
    {
        $orphans = (int)$this->scalar(
            'SELECT COUNT(*) FROM symbol s LEFT JOIN module m ON m.id = s.module_id WHERE m.id IS NULL'
        );

        self::assertSame(
            0,
            $orphans,
            'Найден символ без модуля-владельца — прямое нарушение NON-NEGOTIABLE Принципа II',
        );
    }

    public function testSnapshotCarriesBuildVersion(): void
    {
        self::assertSame('99.100.200', $this->scalar('SELECT sm_version FROM snapshot WHERE id = 1'));
    }

    public function testMainModuleUsesSmVersionSource(): void
    {
        $row = $this->query('SELECT version, version_source FROM module WHERE code = ?', ['main']);

        self::assertCount(1, $row);
        self::assertSame('99.100.200', $row[0]['version']);
        self::assertSame('sm_version', $row[0]['version_source']);
    }

    private function digest(PDO $pdo, string $table): string
    {
        // Сортировка по всем колонкам, кроме автоинкрементного id: он зависит
        // от порядка вставки, а содержимое — нет.
        $rows = $pdo->query("SELECT * FROM {$table}")->fetchAll();

        $normalized = array_map(static function (array $row): string {
            unset($row['id']);
            ksort($row);

            return json_encode($row, JSON_UNESCAPED_UNICODE) ?: '';
        }, $rows);

        sort($normalized);

        return hash('sha256', implode("\n", $normalized));
    }
}
