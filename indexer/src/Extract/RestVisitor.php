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
 * ключом области видимости, у других — на верхнем уровне. Поэтому собираются
 * ВСЕ строковые ключи на любой глубине, отобранные по форме имени REST-метода:
 * строчные буквы, цифры, точки и подчёркивания, минимум одна точка.
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

            return null;
        }

        if (!$node instanceof Node\Stmt\ClassMethod) {
            return null;
        }

        if ($node->name->toLowerString() !== self::HANDLER || $node->stmts === null) {
            return null;
        }

        $this->collect($node->stmts, $node->getStartLine());

        return null;
    }

    /** @return list<array{name: string, handler: string|null, line: int}> */
    public function methods(): array
    {
        return $this->methods;
    }

    /** @param Node[] $nodes */
    private function collect(array $nodes, int $fallbackLine): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node\Expr\Array_) {
                foreach ($node->items as $item) {
                    if ($item === null) {
                        continue;
                    }

                    if ($item->key instanceof Node\Scalar\String_ && self::looksLikeRestMethod($item->key->value)) {
                        $this->methods[] = [
                            'name' => $item->key->value,
                            'handler' => $this->currentClass,
                            'line' => $item->key->getStartLine() ?: $fallbackLine,
                        ];
                    }

                    $this->collect([$item->value], $fallbackLine);
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
                $this->collect($children, $fallbackLine);
            }
        }
    }

    public static function looksLikeRestMethod(string $value): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/', $value) === 1;
    }
}
