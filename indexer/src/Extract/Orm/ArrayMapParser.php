<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract\Orm;

use BitrixMcp\Indexer\Extract\SignatureRenderer;
use PhpParser\Node;

/**
 * Разбор карты полей в форме литерального массива — преобладающей в ядре
 * (1463 файла с getMap(), из них 959 в этой форме).
 *
 * ```php
 * 'ID' => ['data_type' => 'integer', 'primary' => true, 'autocomplete' => true],
 * 'CODE' => ['data_type' => 'string', 'validation' => function () { ... }],
 * ```
 *
 * Ключи и булевы признаки — литералы и извлекаются целиком. Замыкания
 * в `default_value` и `validation` статически не вычислимы и помечаются
 * как неразрешённые, а не отбрасываются молча (Принцип III).
 */
final class ArrayMapParser
{
    public function __construct(
        private readonly SignatureRenderer $renderer = new SignatureRenderer(),
    ) {
    }

    /**
     * @return array{0: list<ExtractedOrmField>, 1: bool, 2: string|null}
     *         поля, полнота, причина неполноты
     */
    public function parse(Node\Expr\Array_ $array): array
    {
        $fields = [];
        $unresolved = [];
        $skipped = 0;

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            if (!$item->key instanceof Node\Scalar\String_) {
                // Ключ вычисляется — имя поля неизвестно. Пропуск отмечается,
                // потому что молчаливая потеря поля недопустима.
                $skipped++;
                continue;
            }

            $name = $item->key->value;

            if (!$item->value instanceof Node\Expr\Array_) {
                // Значение не является описанием поля (например, ссылка
                // на константу). Имя знаем, тип — нет.
                $fields[] = ExtractedOrmField::simple($name, null);
                continue;
            }

            [$field, $hasUnresolved] = $this->parseField($name, $item->value);
            $fields[] = $field;

            if ($hasUnresolved) {
                $unresolved[] = $name;
            }
        }

        $reasons = [];

        if ($unresolved !== []) {
            // Имена НЕ перечисляются: каждое такое поле уже несёт признак
            // is_default_resolved = 0, и дублирование списка в причине только
            // раздувает ответ. Причина объясняет класс проблемы, поле — частность.
            $reasons[] = sprintf(
                'у %d полей значения по умолчанию заданы замыканиями и статически не вычислимы',
                count($unresolved),
            );
        }

        if ($skipped > 0) {
            $reasons[] = sprintf('пропущено полей с вычисляемым именем: %d', $skipped);
        }

        return [$fields, $reasons === [], $reasons === [] ? null : implode('; ', $reasons)];
    }

    /** @return array{0: ExtractedOrmField, 1: bool} */
    private function parseField(string $name, Node\Expr\Array_ $spec): array
    {
        $dataType = null;
        $isPrimary = false;
        $isRequired = false;
        $isAutocomplete = false;
        $defaultRepr = null;
        $defaultResolved = true;
        $referenceTarget = null;
        $join = null;
        $values = null;
        $hasUnresolved = false;

        foreach ($spec->items as $entry) {
            if ($entry === null || !$entry->key instanceof Node\Scalar\String_) {
                continue;
            }

            $key = strtolower($entry->key->value);
            $value = $entry->value;

            switch ($key) {
                case 'data_type':
                    $dataType = $value instanceof Node\Scalar\String_
                        ? $value->value
                        : $this->classNameOf($value);
                    break;

                case 'primary':
                    $isPrimary = $this->isTrue($value);
                    break;

                case 'required':
                    $isRequired = $this->isTrue($value);
                    break;

                case 'autocomplete':
                    $isAutocomplete = $this->isTrue($value);
                    break;

                case 'default_value':
                    if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
                        $defaultResolved = false;
                        $hasUnresolved = true;
                        $defaultRepr = null;
                    } else {
                        $defaultRepr = $this->renderer->renderExpression($value);
                    }
                    break;

                case 'validation':
                    if ($value instanceof Node\Expr\Closure || $value instanceof Node\Expr\ArrowFunction) {
                        $hasUnresolved = true;
                    }
                    break;

                case 'reference':
                    $join = $this->renderer->renderExpression($value);
                    break;

                case 'data_type_class':
                case 'entity':
                    $referenceTarget = $this->classNameOf($value);
                    break;

                case 'values':
                    $values = $this->renderer->renderExpression($value);
                    break;
            }
        }

        // В форме массива связь описывается парой data_type = имя сущности
        // плюс ключ reference с условием соединения.
        if ($join !== null && $referenceTarget === null && $dataType !== null) {
            $referenceTarget = $dataType;
        }

        return [
            new ExtractedOrmField(
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
            ),
            $hasUnresolved,
        ];
    }

    private function isTrue(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\ConstFetch
            && $expr->name->toLowerString() === 'true';
    }

    private function classNameOf(Node\Expr $expr): ?string
    {
        if (
            $expr instanceof Node\Expr\ClassConstFetch
            && $expr->class instanceof Node\Name
            && $expr->name instanceof Node\Identifier
            && $expr->name->toLowerString() === 'class'
        ) {
            return '\\' . $expr->class->toString();
        }

        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        return null;
    }
}
