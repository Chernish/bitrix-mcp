<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract\Orm;

use BitrixMcp\Indexer\Extract\SignatureRenderer;
use PhpParser\Node;

/**
 * Разбор карты полей в форме объектов с цепочкой конфигурации.
 *
 * ```php
 * (new IntegerField('ID'))->configurePrimary(true)->configureAutocomplete(true),
 * (new Reference('IMAGE', \Bitrix\Main\FileTable::class, Join::on('this.IMAGE_ID', 'ref.ID'))),
 * (new EnumField('MESSAGE_TYPE', ['values' => ['text', 'html']]))->configureDefaultValue('text'),
 * ```
 *
 * Это отдельный разборщик, а не ветка в разборе массива: 504 файла ядра
 * используют именно эту форму, и она не сводится к первой ни структурно,
 * ни по набору извлекаемых признаков. Реализация одной формы оставила бы
 * вторую невидимой для агента без каких-либо признаков сбоя (R-003).
 */
final class FluentMapParser
{
    public function __construct(
        private readonly SignatureRenderer $renderer = new SignatureRenderer(),
    ) {
    }

    /**
     * @return array{0: list<ExtractedOrmField>, 1: bool, 2: string|null}
     */
    public function parse(Node\Expr\Array_ $array): array
    {
        $fields = [];
        $skipped = 0;

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            $field = $this->parseItem($item->value);

            if ($field === null) {
                $skipped++;
                continue;
            }

            $fields[] = $field;
        }

        $reason = $skipped > 0
            ? sprintf('пропущено элементов, не сводимых к объявлению поля: %d', $skipped)
            : null;

        return [$fields, $reason === null, $reason];
    }

    private function parseItem(Node\Expr $expr): ?ExtractedOrmField
    {
        // Разматываем цепочку ->configureXxx() до корневого new.
        $chain = [];
        $cursor = $expr;

        while ($cursor instanceof Node\Expr\MethodCall) {
            if ($cursor->name instanceof Node\Identifier) {
                $chain[] = $cursor;
            }

            $cursor = $cursor->var;
        }

        if (!$cursor instanceof Node\Expr\New_ || !$cursor->class instanceof Node\Name) {
            return null;
        }

        $name = $this->stringArg($cursor, 0);

        if ($name === null) {
            return null;
        }

        $fieldClass = $cursor->class->toString();
        $dataType = $this->dataTypeFromClass($fieldClass);

        $isPrimary = false;
        $isRequired = false;
        $isAutocomplete = false;
        $defaultRepr = null;
        $defaultResolved = true;
        $values = null;

        // Цепочка была собрана от конца к началу — порядок для признаков
        // неважен, каждый вызов независим.
        foreach ($chain as $call) {
            $method = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            $firstArg = $call->args[0] ?? null;
            $argValue = $firstArg instanceof Node\Arg ? $firstArg->value : null;

            switch ($method) {
                case 'configurePrimary':
                    $isPrimary = $argValue === null || $this->isTrue($argValue);
                    break;

                case 'configureRequired':
                    $isRequired = $argValue === null || $this->isTrue($argValue);
                    break;

                case 'configureAutocomplete':
                    $isAutocomplete = $argValue === null || $this->isTrue($argValue);
                    break;

                case 'configureDefaultValue':
                    if ($argValue instanceof Node\Expr\Closure || $argValue instanceof Node\Expr\ArrowFunction) {
                        $defaultResolved = false;
                    } elseif ($argValue !== null) {
                        $defaultRepr = $this->renderer->renderExpression($argValue);
                    }
                    break;

                case 'configureValues':
                    $values = implode(', ', array_map(
                        fn(Node\Arg $a): string => $this->renderer->renderExpression($a->value),
                        array_filter($call->args, static fn($a) => $a instanceof Node\Arg),
                    ));
                    break;
            }
        }

        // Reference: второй аргумент — целевая сущность, третий — условие.
        $referenceTarget = null;
        $join = null;

        if ($this->isReference($fieldClass)) {
            $referenceTarget = $this->classArg($cursor, 1);
            $joinArg = $cursor->args[2] ?? null;
            $join = $joinArg instanceof Node\Arg
                ? $this->renderer->renderExpression($joinArg->value)
                : null;
        }

        // EnumField принимает список значений вторым аргументом.
        if ($values === null) {
            $second = $cursor->args[1] ?? null;

            if ($second instanceof Node\Arg && $second->value instanceof Node\Expr\Array_) {
                $values = $this->renderer->renderExpression($second->value);
            }
        }

        return new ExtractedOrmField(
            name: $name,
            dataType: $dataType,
            isPrimary: $isPrimary,
            isRequired: $isRequired,
            isAutocomplete: $isAutocomplete,
            defaultRepr: $defaultRepr,
            isDefaultResolved: $defaultResolved,
            referenceTargetFqn: $referenceTarget,
            joinCondition: $join,
            valuesRepr: $values,
        );
    }

    /**
     * Тип поля выводится из имени класса: IntegerField → integer.
     * Это разбор имени объявленного класса, а не эвристика по тексту:
     * соответствие задано самим API ORM.
     */
    private function dataTypeFromClass(string $fqn): string
    {
        $short = str_contains($fqn, '\\')
            ? substr($fqn, (int)strrpos($fqn, '\\') + 1)
            : $fqn;

        if ($short === 'Reference') {
            return 'reference';
        }

        $trimmed = preg_replace('/Field$/', '', $short) ?? $short;

        return strtolower($trimmed);
    }

    private function isReference(string $fqn): bool
    {
        return str_ends_with($fqn, 'Reference') || str_ends_with($fqn, '\\Reference');
    }

    private function isTrue(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\ConstFetch
            && $expr->name->toLowerString() === 'true';
    }

    private function stringArg(Node\Expr\New_ $new, int $index): ?string
    {
        $arg = $new->args[$index] ?? null;

        return $arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_
            ? $arg->value->value
            : null;
    }

    private function classArg(Node\Expr\New_ $new, int $index): ?string
    {
        $arg = $new->args[$index] ?? null;

        if (!$arg instanceof Node\Arg) {
            return null;
        }

        if (
            $arg->value instanceof Node\Expr\ClassConstFetch
            && $arg->value->class instanceof Node\Name
            && $arg->value->name instanceof Node\Identifier
            && $arg->value->name->toLowerString() === 'class'
        ) {
            return '\\' . $arg->value->class->toString();
        }

        if ($arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }

        return null;
    }
}
