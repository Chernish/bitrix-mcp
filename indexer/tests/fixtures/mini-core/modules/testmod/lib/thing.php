<?php

namespace Test\Mod;

use Test\Mod\Base\Entity as BaseEntity;
use Test\Mod\Concerns\LogsActivity;
use Test\Mod\Contracts\Speaks;

/**
 * Класс с импортами и псевдонимом импорта.
 *
 * Проверяет, что резолв имён работает: `extends BaseEntity` обязан дать
 * полное имя \Test\Mod\Base\Entity, а не текст «BaseEntity».
 *
 * @deprecated 42.0.0 используйте \Test\Mod\NewThing
 */
class Thing extends BaseEntity implements Speaks
{
    use LogsActivity;

    public const STATUS_ACTIVE = 'A';

    public static function findAll(array $filter = [], ?int $limit = null): array
    {
        helper_function('lookup');
        self::buildOne();

        return [];
    }

    private static function buildOne(): void
    {
    }

    protected function mutate(int &$counter, string ...$rest): ?self
    {
        return null;
    }

    private function hidden(): void
    {
    }
}

function helper_function(string $value = 'default'): string
{
    return strtoupper($value);
}

class_alias('\Test\Mod\Thing', '\Test\Mod\ThingAlias');
