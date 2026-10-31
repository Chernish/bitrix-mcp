<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Извлечение REST-методов, публикуемых модулем наружу.
 *
 * Методы объявляются строковыми ключами массива внутри обработчика
 * `OnRestServiceBuildDescription()`:
 *
 * ```php
 * public static function OnRestServiceBuildDescription()
 * {
 *     return ['calendar' => [
 *         'calendar.event.get' => [__CLASS__, 'EventGet'],
 *         'calendar.event.add' => [__CLASS__, 'EventAdd'],
 *     ]];
 * }
 * ```
 *
 * Форма вложенности различается между модулями: у одних методы лежат под
 * ключом области видимости, у других — на верхнем уровне. Поэтому внутри
 * фактически возвращаемого описания собираются строковые ключи на любой глубине,
 * отобранные по форме имени REST-метода: строчные буквы, цифры, точки
 * и подчёркивания, минимум одна точка. Локальные невозвращаемые массивы
 * публикацией не являются и не индексируются.
 *
 * Это отбор по форме объявленного идентификатора, а не догадка о содержимом:
 * имя REST-метода Битрикса имеет фиксированный синтаксис.
 */
final class RestVisitor extends NodeVisitorAbstract
{
    private const HANDLER = 'onrestservicebuilddescription';

    /** @var list<array{name: string, handler: string|null, line: int}> */
    private array $methods = [];

    private ?string $currentClass = null;

    public function beforeTraverse(array $nodes): ?array
    {
        $this->methods = [];
        $this->currentClass = null;

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Stmt\ClassLike && $node->name !== null) {
            $this->currentClass = $node->namespacedName?->toString() ?? $node->name->toString();
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if (
            $node instanceof Node\Stmt\ClassMethod
            && $node->name->toLowerString() === self::HANDLER
            && $node->stmts !== null
        ) {
            $this->collectReturns($node);
        }

        return null;
    }

    /** @return list<array{name: string, handler: string|null, line: int}> */
    public function methods(): array
    {
        return $this->methods;
    }

    private function collectReturns(Node\Stmt\ClassMethod $method): void
    {
        $returns = [];
        $arrays = [];

        $this->scanMethod($method->stmts ?? [], static function (Node $node) use (&$returns, &$arrays): void {
            if ($node instanceof Node\Stmt\Return_) {
                $returns[] = $node;
                return;
            }

            if (
                $node instanceof Node\Expr\Assign
                && $node->var instanceof Node\Expr\Variable
                && is_string($node->var->name)
                && $node->expr instanceof Node\Expr\Array_
            ) {
                $arrays[$node->var->name][] = [
                    'position' => $node->getStartFilePos(),
                    'expression' => $node->expr,
                ];
            }
        });

        foreach ($returns as $return) {
            if (!$return->expr instanceof Node\Expr) {
                continue;
            }

            $this->collect([$return->expr], $method->getStartLine(), $arrays, $return->getStartFilePos());
        }
    }

    /**
     * @param Node[] $nodes
     * @param callable(Node): void $visitor
     */
    private function scanMethod(array $nodes, callable $visitor): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                continue;
            }

            $visitor($node);

            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->{$name};
                if ($value instanceof Node) {
                    $this->scanMethod([$value], $visitor);
                } elseif (is_array($value)) {
                    $children = array_filter($value, static fn($item): bool => $item instanceof Node);
                    $this->scanMethod(array_values($children), $visitor);
                }
            }
        }
    }

    /**
     * @param array<string, list<array{position: int, expression: Node\Expr\Array_}>> $arrays
     */
    private function resolveArrayVariable(Node\Expr $expression, array $arrays, int $returnPosition): Node\Expr
    {
        if (!$expression instanceof Node\Expr\Variable || !is_string($expression->name)) {
            return $expression;
        }

        $selected = null;
        $limit = $returnPosition >= 0 ? $returnPosition : PHP_INT_MAX;

        foreach ($arrays[$expression->name] ?? [] as $candidate) {
            if ($candidate['position'] <= $limit) {
                $selected = $candidate;
            }
        }

        return $selected['expression'] ?? $expression;
    }

    /**
     * @param Node[] $nodes
     * @param array<string, list<array{position: int, expression: Node\Expr\Array_}>> $arrays
     */
    private function collect(
        array $nodes,
        int $fallbackLine,
        array $arrays = [],
        int $returnPosition = PHP_INT_MAX,
    ): void {
        foreach ($nodes as $node) {
            if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) {
                continue;
            }

            if ($node instanceof Node\Expr) {
                $node = $this->resolveArrayVariable($node, $arrays, $returnPosition);
            }

            if ($node instanceof Node\Expr\Array_) {
                foreach ($node->items as $item) {
                    if ($item === null) {
                        continue;
                    }

                    if ($item->key instanceof Node\Scalar\String_ && self::looksLikeRestMethod($item->key->value)) {
                        $this->methods[] = [
                            'name' => $item->key->value,
                            'handler' => $this->handler($item->value),
                            'line' => $item->key->getStartLine() ?: $fallbackLine,
                        ];
                    }

                    $this->collect([$item->value], $fallbackLine, $arrays, $returnPosition);
                }

                continue;
            }

            $children = [];

            foreach ($node->getSubNodeNames() as $name) {
                /** @var mixed $value */
                $value = $node->{$name};

                if ($value instanceof Node) {
                    $children[] = $value;
                } elseif (is_array($value)) {
                    foreach ($value as $sub) {
                        if ($sub instanceof Node) {
                            $children[] = $sub;
                        }
                    }
                }
            }

            if ($children !== []) {
                $this->collect($children, $fallbackLine, $arrays, $returnPosition);
            }
        }
    }

    private function handler(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if (!$expr instanceof Node\Expr\Array_) {
            return null;
        }

        foreach ($expr->items as $item) {
            if ($item?->key instanceof Node\Scalar\String_ && $item->key->value === 'callback') {
                return $this->handler($item->value);
            }
        }

        $class = $expr->items[0] ?? null;
        $method = $expr->items[1] ?? null;
        if ($class === null || !$method?->value instanceof Node\Scalar\String_) {
            return null;
        }

        $className = $this->className($class->value);

        return $className === null ? null : $className . '::' . $method->value->value;
    }

    private function className(Node\Expr $expr): ?string
    {
        if ($expr instanceof Node\Scalar\MagicConst\Class_) {
            return $this->currentClass === null ? null : '\\' . ltrim($this->currentClass, '\\');
        }

        if ($expr instanceof Node\Scalar\String_) {
            return '\\' . ltrim($expr->value, '\\');
        }

        if (
            $expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && $expr->name->toLowerString() === 'class'
        ) {
            $class = $expr->class->toString();
            if (in_array(strtolower($class), ['self', 'static'], true)) {
                $class = $this->currentClass ?? '';
            }

            return $class === '' || strtolower($class) === 'parent'
                ? null
                : '\\' . ltrim($class, '\\');
        }

        return null;
    }

    public static function looksLikeRestMethod(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $value) === 1;
    }
}
