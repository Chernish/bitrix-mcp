<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Integration;

/**
 * Регрессия R-001 на уровне полного прогона индексации.
 *
 * Отдельно от модульного теста разбора: потеря может произойти не только
 * в парсере, но и при обходе, атрибуции или записи. Этот тест проверяет
 * результат в индексе, а не поведение одного компонента.
 */
final class ShortTagRegressionTest extends IndexBuildTestCase
{
    public function testClassFromShortTagFileIsIndexed(): void
    {
        $rows = $this->query(
            'SELECT s.fqn, s.api_style, m.code, m.version, f.had_short_open_tag
             FROM symbol s
             JOIN module m ON m.id = s.module_id
             JOIN file f ON f.id = s.file_id
             WHERE s.short_name = ?',
            ['CTestModLegacy'],
        );

        self::assertCount(
            1,
            $rows,
            'Класс из файла с коротким тегом отсутствует в индексе — ' .
            'ровно тот тихий отказ, который описан в R-001',
        );

        self::assertSame(1, (int)$rows[0]['had_short_open_tag'], 'Признак короткого тега не записан');
        self::assertSame('testmod', $rows[0]['code']);
        self::assertSame('42.7.0', $rows[0]['version'], 'Версия модуля не привязана к символу');
        self::assertSame('legacy', $rows[0]['api_style']);
    }

    public function testMethodFromShortTagFileHasSignature(): void
    {
        $signature = $this->scalar(
            'SELECT me.signature FROM method me
             JOIN symbol s ON s.id = me.symbol_id
             WHERE s.short_name = ? AND me.name = ?',
            ['CTestModLegacy', 'GetList'],
        );

        self::assertIsString($signature);
        self::assertStringContainsString('public static function GetList', $signature);
        // Значение по умолчанию сохраняется в ИСХОДНОМ виде, включая тип кавычек:
        // это представление, а не вычисленное значение (Принцип VI).
        self::assertStringContainsString('$order = "ID"', $signature);
        self::assertStringContainsString('$by = "ASC"', $signature);
    }

    public function testShortTagFilesAreCounted(): void
    {
        $count = (int)$this->scalar('SELECT COUNT(*) FROM file WHERE had_short_open_tag = 1');

        self::assertGreaterThan(0, $count);
    }

    public function testSnapshotRecordsShortOpenTagState(): void
    {
        $state = (int)$this->scalar('SELECT short_open_tag_enabled FROM snapshot WHERE id = 1');

        self::assertSame(
            1,
            $state,
            'Снимок обязан фиксировать состояние настройки: иначе непригодный ' .
            'индекс невозможно распознать постфактум',
        );
    }
}
