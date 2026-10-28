<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Извлечение зависимостей между модулями (FR-011).
 *
 * Формальной машиночитаемой декларации зависимостей у Битрикса нет, поэтому
 * источником берутся фактические подключения модулей в коде:
 *
 *   Loader::includeModule('crm')
 *   CModule::IncludeModule('iblock')
 *   \Bitrix\Main\Loader::includeModule('sale')
 *
 * Это структурный факт из AST — какой модуль реально нужен коду, — а не
 * догадка. Слабое место подхода честное и его стоит помнить: подключение
 * внутри редко исполняемой ветки выглядит так же, как обязательная
 * зависимость, а динамическое имя не извлекается вовсе.
 */
final class ModuleDependencyVisitor extends NodeVisitorAbstract
{
    private const METHODS = ['includemodule'];

    /** @var array<string, true> */
    private array $modules = [];

    public function beforeTraverse(array $nodes): ?array
    {
        $this->modules = [];

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if (!$node instanceof Node\Expr\StaticCall) {
            return null;
        }

        if (!$node->name instanceof Node\Identifier) {
            return null;
        }

        if (!in_array($node->name->toLowerString(), self::METHODS, true)) {
            return null;
        }

        $arg = $node->args[0] ?? null;

        if (!$arg instanceof Node\Arg || !$arg->value instanceof Node\Scalar\String_) {
            return null;
        }

        $code = trim($arg->value->value);

        // Имя модуля Битрикса: строчные буквы, цифры, точки и подчёркивания.
        if ($code !== '' && preg_match('/^[a-z][a-z0-9._]*$/', $code) === 1) {
            $this->modules[$code] = true;
        }

        return null;
    }

    /** @return list<string> */
    public function modules(): array
    {
        $codes = array_keys($this->modules);
        sort($codes);

        return $codes;
    }
}
