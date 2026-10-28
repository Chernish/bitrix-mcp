<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract\Component;

use BitrixMcp\Indexer\Extract\SignatureRenderer;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Извлечение компонентов из `.parameters.php` и `.description.php`.
 *
 * Файлы НЕ выполняются (Принцип VI). Извлекается литеральная структура
 * присваивания `$arComponentParameters` и `$arComponentDescription`, а всё,
 * что вычисляется в рантайме, помечается признаком `is_default_resolved = 0`.
 *
 * Языковые файлы (`.../lang/ru/.parameters.php`) исключаются: они содержат
 * переводы, а не объявления параметров. Из 2622 файлов с таким именем
 * настоящих компонентов только 901.
 */
final class ComponentExtractor
{
    private readonly NodeFinder $finder;

    public function __construct(
        private readonly SignatureRenderer $renderer = new SignatureRenderer(),
    ) {
        $this->finder = new NodeFinder();
    }

    /**
     * @param Node\Stmt[] $stmts
     */
    public function extract(array $stmts, string $relPath): ?ExtractedComponent
    {
        $identity = self::identityFromPath($relPath);

        if ($identity === null) {
            return null;
        }

        [$namespace, $name, $file] = $identity;

        if ($file === 'parameters') {
            $params = $this->extractParameters($stmts);

            // Компонент регистрируется ДАЖЕ когда параметры не извлеклись.
            // Иначе он исчезает из индекса без следа, а агент получает
            // «не найдено» на существующий компонент (Принцип III).
            return new ExtractedComponent(
                $namespace,
                $name,
                $namespace . ':' . $name,
                null,
                null,
                $params ?? [],
                $params !== null,
            );
        }

        [$title, $description] = $this->extractDescription($stmts);

        if ($title === null && $description === null) {
            return null;
        }

        return new ExtractedComponent($namespace, $name, $namespace . ':' . $name, $title, $description, []);
    }

    /**
     * Разбор пути вида `modules/<mod>/install/components/<ns>/<name>/.parameters.php`.
     *
     * @return array{0: string, 1: string, 2: string}|null пространство, имя, вид файла
     */
    public static function identityFromPath(string $relPath): ?array
    {
        $base = basename($relPath);

        $kind = match ($base) {
            '.parameters.php' => 'parameters',
            '.description.php' => 'description',
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        // Языковые файлы содержат переводы, а не объявления.
        if (str_contains($relPath, '/lang/')) {
            return null;
        }

        $parts = explode('/', trim(dirname($relPath), '/'));
        $count = count($parts);

        if ($count < 2) {
            return null;
        }

        $name = $parts[$count - 1];
        $namespace = $parts[$count - 2];

        // Каталог компонента лежит непосредственно под `components/<ns>/`.
        if (($parts[$count - 3] ?? '') !== 'components') {
            return null;
        }

        return [$namespace, $name, $kind];
    }

    /**
     * @param Node\Stmt[] $stmts
     * @return list<ExtractedComponentParam>|null
     */
    private function extractParameters(array $stmts): ?array
    {
        $array = $this->findAssignedArray($stmts, 'arComponentParameters');

        if ($array === null) {
            return null;
        }

        $parameters = null;

        foreach ($array->items as $item) {
            if (
                $item?->key instanceof Node\Scalar\String_
                && strtoupper($item->key->value) === 'PARAMETERS'
                && $item->value instanceof Node\Expr\Array_
            ) {
                $parameters = $item->value;
                break;
            }
        }

        if ($parameters === null) {
            return [];
        }

        $result = [];

        foreach ($parameters->items as $item) {
            if (!$item?->key instanceof Node\Scalar\String_ || !$item->value instanceof Node\Expr\Array_) {
                continue;
            }

            $result[] = $this->parseParam($item->key->value, $item->value);
        }

        return $result;
    }

    private function parseParam(string $code, Node\Expr\Array_ $spec): ExtractedComponentParam
    {
        $type = null;
        $default = null;
        $resolved = true;
        $parent = null;
        $multiple = false;
        $values = null;

        foreach ($spec->items as $entry) {
            if ($entry === null || !$entry->key instanceof Node\Scalar\String_) {
                continue;
            }

            $key = strtoupper($entry->key->value);
            $value = $entry->value;

            switch ($key) {
                case 'TYPE':
                    $type = $value instanceof Node\Scalar\String_ ? $value->value : null;
                    break;

                case 'DEFAULT':
                    if ($value instanceof Node\Scalar\String_) {
                        $default = $value->value;
                    } elseif ($value instanceof Node\Scalar\Int_) {
                        $default = (string)$value->value;
                    } else {
                        // GetMessage(...), COption::GetOptionString(...) и прочие
                        // вычисления требуют загруженного ядра и живой БД.
                        $resolved = false;
                        $default = null;
                    }
                    break;

                case 'PARENT':
                    $parent = $value instanceof Node\Scalar\String_ ? $value->value : null;
                    break;

                case 'MULTIPLE':
                    $multiple = $value instanceof Node\Scalar\String_
                        && strtoupper($value->value) === 'Y';
                    break;

                case 'VALUES':
                    if ($value instanceof Node\Expr\Array_) {
                        $rendered = $this->renderer->renderExpression($value);
                        $values = mb_strlen($rendered) <= 200 ? $rendered : null;
                    }
                    break;
            }
        }

        return new ExtractedComponentParam($code, $type, $default, $resolved, $parent, $multiple, $values);
    }

    /**
     * @param Node\Stmt[] $stmts
     * @return array{0: string|null, 1: string|null}
     */
    private function extractDescription(array $stmts): array
    {
        $array = $this->findAssignedArray($stmts, 'arComponentDescription');

        if ($array === null) {
            return [null, null];
        }

        $name = null;
        $description = null;

        foreach ($array->items as $item) {
            if (!$item?->key instanceof Node\Scalar\String_) {
                continue;
            }

            $key = strtoupper($item->key->value);

            // Значения почти всегда GetMessage(...) — переводы недоступны
            // без выполнения кода. Берём то, что задано литералом.
            if ($item->value instanceof Node\Scalar\String_) {
                if ($key === 'NAME') {
                    $name = $item->value->value;
                } elseif ($key === 'DESCRIPTION') {
                    $description = $item->value->value;
                }
            }
        }

        return [$name, $description];
    }

    /**
     * @param Node\Stmt[] $stmts
     */
    private function findAssignedArray(array $stmts, string $variable): ?Node\Expr\Array_
    {
        /** @var Node\Expr\Assign|null $assign */
        $assign = $this->finder->findFirst($stmts, static function (Node $node) use ($variable): bool {
            return $node instanceof Node\Expr\Assign
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === $variable
                && $node->expr instanceof Node\Expr\Array_;
        });

        return $assign?->expr instanceof Node\Expr\Array_ ? $assign->expr : null;
    }
}
