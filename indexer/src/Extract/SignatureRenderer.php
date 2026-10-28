<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

use PhpParser\Node;
use PhpParser\PrettyPrinter\Standard;

/**
 * Рендеринг сигнатуры метода в вид, пригодный для агента.
 *
 * Результат сохраняется в индекс заранее. Денормализация оправдана Принципом IV:
 * сигнатура — ровно то, что уходит агенту, она не меняется между запросами,
 * и собирать её из параметров на каждый запрос значит платить за неизменяемое.
 */
final class SignatureRenderer
{
    private readonly Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    /**
     * @param list<ExtractedParameter> $params
     */
    public function render(
        string $name,
        string $visibility,
        bool $isStatic,
        bool $isAbstract,
        array $params,
        ?string $returnType,
    ): string {
        $prefix = $visibility;

        if ($isAbstract) {
            $prefix = 'abstract ' . $prefix;
        }

        if ($isStatic) {
            $prefix .= ' static';
        }

        $rendered = [];

        foreach ($params as $p) {
            $part = '';

            if ($p->type !== null) {
                $part .= $p->type . ' ';
            }

            if ($p->byRef) {
                $part .= '&';
            }

            if ($p->variadic) {
                $part .= '...';
            }

            $part .= '$' . $p->name;

            if ($p->defaultRepr !== null) {
                $part .= ' = ' . $p->defaultRepr;
            }

            $rendered[] = $part;
        }

        $signature = $prefix . ' function ' . $name . '(' . implode(', ', $rendered) . ')';

        if ($returnType !== null) {
            $signature .= ': ' . $returnType;
        }

        return $signature;
    }

    /** @param list<ExtractedParameter> $params */
    public function renderFunction(string $name, array $params, ?string $returnType): string
    {
        $method = $this->render($name, '', false, false, $params, $returnType);

        return ltrim($method);
    }

    /**
     * Текстовое представление выражения по умолчанию.
     * Именно представление, а не вычисленное значение (Принцип VI).
     */
    public function renderExpression(Node\Expr $expr): string
    {
        return $this->printer->prettyPrintExpr($expr);
    }

    /**
     * Тип в текстовом виде. К моменту вызова NameResolver уже разрешил
     * имена в полные, поэтому результат не зависит от импортов файла.
     */
    public static function renderType(null|Node\Identifier|Node\Name|Node\ComplexType $type): ?string
    {
        return match (true) {
            $type === null => null,
            $type instanceof Node\Identifier => $type->toString(),
            $type instanceof Node\Name => '\\' . $type->toString(),
            $type instanceof Node\NullableType => '?' . self::renderType($type->type),
            $type instanceof Node\UnionType => implode('|', array_map(
                static fn($t) => self::renderType($t) ?? 'mixed',
                $type->types,
            )),
            $type instanceof Node\IntersectionType => implode('&', array_map(
                static fn($t) => self::renderType($t) ?? 'mixed',
                $type->types,
            )),
            default => null,
        };
    }
}
