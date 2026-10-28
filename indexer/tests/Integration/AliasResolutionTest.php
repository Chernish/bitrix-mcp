<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Integration;

/**
 * Проверка R-006 на полном прогоне.
 *
 * Без индексации псевдонимов поиск отвечал бы «не найдено» на имя, которым
 * пользуются повсеместно, но под которым класс не объявлен.
 */
final class AliasResolutionTest extends IndexBuildTestCase
{
    public function testAliasIsIndexedWithBothNames(): void
    {
        $rows = $this->query(
            'SELECT a.alias_fqn, a.target_fqn, m.code, m.version
             FROM symbol_alias a JOIN module m ON m.id = a.module_id',
        );

        self::assertCount(1, $rows);
        self::assertSame('\Test\Mod\ThingAlias', $rows[0]['alias_fqn']);
        self::assertSame('\Test\Mod\Thing', $rows[0]['target_fqn']);
        self::assertSame('testmod', $rows[0]['code']);
        self::assertSame('42.7.0', $rows[0]['version'], 'Псевдоним записан без версионной атрибуции');
    }

    public function testAliasIsSearchableInLowerCase(): void
    {
        $target = $this->scalar(
            'SELECT target_fqn FROM symbol_alias WHERE alias_fqn_lower = ?',
            ['\test\mod\thingalias'],
        );

        self::assertSame('\Test\Mod\Thing', $target);
    }

    public function testAliasShortNameIsIndexed(): void
    {
        $target = $this->scalar(
            'SELECT target_fqn FROM symbol_alias WHERE alias_short_lower = ?',
            ['thingalias'],
        );

        self::assertSame('\Test\Mod\Thing', $target, 'Поиск псевдонима по короткому имени не работает');
    }

    public function testInterfacesAreRecorded(): void
    {
        $rows = $this->query(
            'SELECT i.interface_fqn FROM symbol_interface i
             JOIN symbol s ON s.id = i.symbol_id WHERE s.short_name = ?',
            ['Thing'],
        );

        self::assertSame(
            ['\Test\Mod\Contracts\Speaks'],
            array_column($rows, 'interface_fqn'),
            'Список реализуемых интерфейсов не заполнен, хотя контракт его обещает',
        );
    }
}
