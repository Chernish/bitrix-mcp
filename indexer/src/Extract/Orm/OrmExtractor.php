<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract\Orm;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Извлечение ORM-сущности из объявления класса.
 *
 * Признак сущности — объявленный метод `getMap()`. Это структурный факт,
 * а не догадка по имени класса: цепочку наследования до DataManager нельзя
 * разрешить в пределах одного файла, а суффикс `Table` в имени встречается
 * и у классов, не являющихся сущностями.
 *
 * Все три вида неполноты помечаются с причиной: замыкания в значениях,
 * наследование части карты от родителя, вычисляемое имя таблицы.
 */
final class OrmExtractor
{
    public function __construct(
        private readonly ArrayMapParser $arrayParser = new ArrayMapParser(),
        private readonly FluentMapParser $fluentParser = new FluentMapParser(),
    ) {
    }

    public function extract(ClassLike $node): ?ExtractedOrmEntity
    {
        $getMap = $this->findMethod($node, 'getMap');

        if ($getMap === null) {
            return null;
        }

        [$tableName, $tableSource] = $this->extractTableName($node);
        $return = $this->findReturnExpression($getMap);

        if ($return === null) {
            return new ExtractedOrmEntity(
                tableName: $tableName,
                tableNameSource: $tableSource,
                extractionStyle: 'unresolved',
                isComplete: false,
                incompleteReason: 'тело getMap() не содержит единственного статически разрешимого возврата массива',
                fields: [],
            );
        }

        $inheritedNote = null;
        $array = null;

        if ($return instanceof Node\Expr\Array_) {
            $array = $return;
        } elseif ($this->isArrayMergeWithParent($return)) {
            // array_merge(parent::getMap(), [...]) — встречается в 6 файлах ядра.
            $array = $this->firstArrayArgument($return);
            $inheritedNote = 'часть полей наследуется от родителя и в этой записи отсутствует';
        }

        if ($array === null) {
            return new ExtractedOrmEntity(
                tableName: $tableName,
                tableNameSource: $tableSource,
                extractionStyle: 'unresolved',
                isComplete: false,
                incompleteReason: 'карта полей собирается динамически, статически не извлекается',
                fields: [],
            );
        }

        $style = $this->detectStyle($array);

        [$fields, $complete, $reason] = match ($style) {
            'array' => $this->arrayParser->parse($array),
            'fluent' => $this->fluentParser->parse($array),
            default => $this->parseMixed($array),
        };

        if ($inheritedNote !== null) {
            $complete = false;
            $reason = $reason === null ? $inheritedNote : $reason . '; ' . $inheritedNote;
        }

        if ($tableSource === 'unresolved' && $tableName === null) {
            $complete = false;
            $note = 'имя таблицы вычисляется и статически не извлекается';
            $reason = $reason === null ? $note : $reason . '; ' . $note;
        }

        return new ExtractedOrmEntity(
            tableName: $tableName,
            tableNameSource: $tableSource,
            extractionStyle: $style,
            isComplete: $complete,
            incompleteReason: $reason,
            fields: $fields,
        );
    }

    /**
     * Смешанная карта: часть полей объявлена массивом, часть — объектами.
     *
     * Элементы РАЗДЕЛЯЮТСЯ по форме, и каждый разборщик получает только свою
     * часть. Прогон обоих по всему массиву дал бы правдоподобную, но ложную
     * причину неполноты вида «пропущено 33 элемента» — это были бы элементы
     * чужой формы, разобранные соседним парсером. Ложное объяснение хуже
     * отсутствующего: оно направляет расследование не туда.
     *
     * @return array{0: list<ExtractedOrmField>, 1: bool, 2: string|null}
     */
    private function parseMixed(Node\Expr\Array_ $array): array
    {
        $keyed = new Node\Expr\Array_([], $array->getAttributes());
        $unkeyed = new Node\Expr\Array_([], $array->getAttributes());

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->key instanceof Node\Scalar\String_) {
                $keyed->items[] = $item;
            } else {
                $unkeyed->items[] = $item;
            }
        }

        [$arrayFields, $arrayComplete, $reasonA] = $this->arrayParser->parse($keyed);
        [$fluentFields, $fluentComplete, $reasonF] = $this->fluentParser->parse($unkeyed);

        $reasons = array_values(array_filter([$reasonA, $reasonF]));

        return [
            array_merge($arrayFields, $fluentFields),
            $arrayComplete && $fluentComplete,
            $reasons === [] ? null : implode('; ', $reasons),
        ];
    }

    /**
     * Форма определяется по наличию строковых ключей: в форме массива
     * ключ — имя поля, в fluent-форме элементы без ключей.
     */
    private function detectStyle(Node\Expr\Array_ $array): string
    {
        $keyed = 0;
        $unkeyed = 0;

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            if ($item->key instanceof Node\Scalar\String_) {
                $keyed++;
            } else {
                $unkeyed++;
            }
        }

        return match (true) {
            $keyed > 0 && $unkeyed > 0 => 'mixed',
            $unkeyed > 0 => 'fluent',
            default => 'array',
        };
    }

    /** @return array{0: string|null, 1: string} */
    private function extractTableName(ClassLike $node): array
    {
        $method = $this->findMethod($node, 'getTableName');

        if ($method === null) {
            // Имя наследуется от родителя либо выводится ORM автоматически.
            return [null, 'inherited'];
        }

        $return = $this->findReturnExpression($method);

        if ($return instanceof Node\Scalar\String_) {
            return [$return->value, 'literal'];
        }

        return [null, 'unresolved'];
    }

    private function findMethod(ClassLike $node, string $name): ?ClassMethod
    {
        foreach ($node->getMethods() as $method) {
            if (strcasecmp($method->name->toString(), $name) === 0) {
                return $method;
            }
        }

        return null;
    }

    private function findReturnExpression(ClassMethod $method): ?Node\Expr
    {
        $returns = $this->findReturns($method->stmts ?? []);

        return count($returns) === 1 ? $returns[0]->expr : null;
    }

    /**
     * @param array<Node> $nodes
     * @return list<Node\Stmt\Return_>
     */
    private function findReturns(array $nodes): array
    {
        $result = [];

        foreach ($nodes as $node) {
            if ($node instanceof Node\FunctionLike || $node instanceof ClassLike) {
                continue;
            }

            if ($node instanceof Node\Stmt\Return_) {
                $result[] = $node;
                continue;
            }

            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->{$name};
                if ($value instanceof Node) {
                    $result = array_merge($result, $this->findReturns([$value]));
                } elseif (is_array($value)) {
                    $children = array_filter($value, static fn($item): bool => $item instanceof Node);
                    $result = array_merge($result, $this->findReturns(array_values($children)));
                }
            }
        }

        return $result;
    }

    private function isArrayMergeWithParent(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && $expr->name->toLowerString() === 'array_merge';
    }

    private function firstArrayArgument(Node\Expr $expr): ?Node\Expr\Array_
    {
        if (!$expr instanceof Node\Expr\FuncCall) {
            return null;
        }

        foreach ($expr->args as $arg) {
            if ($arg instanceof Node\Arg && $arg->value instanceof Node\Expr\Array_) {
                return $arg->value;
            }
        }

        return null;
    }
}
