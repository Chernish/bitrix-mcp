<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Integration;

final class ExtendedApiIndexTest extends IndexBuildTestCase
{
    public function testFunctionsConstantsAndUsagesArePersistedWithAttribution(): void
    {
        $function = $this->query(
            'SELECT gf.fqn, gf.signature, m.code AS module
             FROM global_function gf JOIN module m ON m.id = gf.module_id
             WHERE gf.short_name = ?',
            ['helper_function'],
        );
        self::assertCount(1, $function);
        self::assertSame('\Test\Mod\helper_function', $function[0]['fqn']);
        self::assertSame('testmod', $function[0]['module']);

        $constant = $this->query(
            'SELECT cc.name, cc.value_repr FROM class_constant cc
             JOIN symbol s ON s.id = cc.symbol_id WHERE s.short_name = ?',
            ['Thing'],
        );
        self::assertSame([['name' => 'STATUS_ACTIVE', 'value_repr' => "'A'"]], $constant);

        $usage = $this->query(
            'SELECT ur.member_name, ur.context_member, m.code AS module
             FROM usage_reference ur JOIN module m ON m.id = ur.module_id
             WHERE ur.member_name = ?',
            ['helper_function'],
        );
        self::assertCount(1, $usage);
        self::assertSame('findAll', $usage[0]['context_member']);
        self::assertSame('testmod', $usage[0]['module']);
    }
}
