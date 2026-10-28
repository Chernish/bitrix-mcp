<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Извлечение псевдонимов class_alias.
 *
 * Без этого поиск отвечал бы «не найдено» на \Bitrix\Sale\OrderTable — форму,
 * которой пользуются повсеместно, хотя объявления с таким именем в ядре нет
 * (класс объявлен как \Bitrix\Sale\Internals\OrderTable). Отказ на существующий
 * и общеупотребительный класс — ровно тот случай, ради исключения которого
 * Принцип I требует давать отказ только для действительно отсутствующего.
 *
 * Сигнатура: class_alias(string $target, string $alias, bool $autoload = true)
 * — целевой класс ПЕРВЫМ аргументом, псевдоним вторым.
 */
final class AliasVisitor extends NodeVisitorAbstract
{
    /** @var list<ExtractedAlias> */
    private array $aliases = [];

    public function beforeTraverse(array $nodes): ?array
    {
        $this->aliases = [];

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if (!$node instanceof Node\Expr\FuncCall) {
            return null;
        }

        if (!$node->name instanceof Node\Name || $node->name->toLowerString() !== 'class_alias') {
            return null;
        }

        $target = $this->stringArg($node, 0);
        $alias = $this->stringArg($node, 1);

        // Оба аргумента должны быть литералами. Динамически собранное имя
        // достроить нельзя, а угадывать запрещено (Принцип III).
        if ($target === null || $alias === null) {
            return null;
        }

        $this->aliases[] = new ExtractedAlias(
            aliasFqn: '\\' . ltrim($alias, '\\'),
            targetFqn: '\\' . ltrim($target, '\\'),
            line: $node->getStartLine(),
        );

        return null;
    }

    /** @return list<ExtractedAlias> */
    public function aliases(): array
    {
        return $this->aliases;
    }

    private function stringArg(Node\Expr\FuncCall $call, int $index): ?string
    {
        $arg = $call->args[$index] ?? null;

        if (!$arg instanceof Node\Arg) {
            return null;
        }

        if ($arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }

        // Форма \Bitrix\Main\FileTable::class тоже даёт имя статически.
        if (
            $arg->value instanceof Node\Expr\ClassConstFetch
            && $arg->value->class instanceof Node\Name
            && $arg->value->name instanceof Node\Identifier
            && $arg->value->name->toLowerString() === 'class'
        ) {
            return $arg->value->class->toString();
        }

        return null;
    }
}
