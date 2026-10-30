<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

use BitrixMcp\Indexer\Extract\Orm\OrmExtractor;
use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeVisitorAbstract;

/**
 * Извлечение классов, интерфейсов, трейтов и перечислений.
 *
 * Работает ПОСЛЕ NameResolver, поэтому все имена уже разрешены в полные и не
 * зависят от импортов файла. Это принципиально: `use Bitrix\Main\Entity\DataManager as DM;`
 * с последующим `extends DM` должен дать полное имя родителя, а текстовая
 * эвристика здесь ошиблась бы (Принцип III).
 */
final class SymbolVisitor extends NodeVisitorAbstract
{
    /** @var list<ExtractedSymbol> */
    private array $symbols = [];

    public function __construct(
        private readonly MethodExtractor $methods = new MethodExtractor(),
        private readonly OrmExtractor $orm = new OrmExtractor(),
    ) {
    }

    public function beforeTraverse(array $nodes): ?array
    {
        $this->symbols = [];

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if (!$node instanceof ClassLike) {
            return null;
        }

        // Анонимные классы не адресуемы по имени и агенту бесполезны.
        if ($node->name === null) {
            return null;
        }

        $fqn = $node->namespacedName?->toString() ?? $node->name->toString();
        $shortName = $node->name->toString();
        $namespace = str_contains($fqn, '\\')
            ? substr($fqn, 0, strrpos($fqn, '\\') ?: 0)
            : null;

        $doc = $node->getDocComment()?->getText();
        [$isDeprecated, $deprecatedNote] = DeprecationDetector::detect($doc);

        $this->symbols[] = new ExtractedSymbol(
            fqn: '\\' . ltrim($fqn, '\\'),
            shortName: $shortName,
            kind: $this->kindOf($node),
            parentFqn: $this->parentOf($node),
            interfaces: $this->interfacesOf($node),
            isAbstract: $node instanceof Class_ && $node->isAbstract(),
            isFinal: $node instanceof Class_ && $node->isFinal(),
            isDeprecated: $isDeprecated,
            deprecatedNote: $deprecatedNote,
            apiStyle: ApiStyle::detect($namespace, $shortName),
            docComment: $doc,
            line: $node->getStartLine(),
            methods: $this->methodsOf($node),
            constants: $this->constantsOf($node),
            traits: $this->traitsOf($node),
            orm: $this->orm->extract($node),
        );

        return null;
    }

    /** @return list<ExtractedSymbol> */
    public function symbols(): array
    {
        return $this->symbols;
    }

    private function kindOf(ClassLike $node): string
    {
        return match (true) {
            $node instanceof Interface_ => 'interface',
            $node instanceof Trait_ => 'trait',
            $node instanceof Enum_ => 'enum',
            default => 'class',
        };
    }

    private function parentOf(ClassLike $node): ?string
    {
        if ($node instanceof Class_ && $node->extends !== null) {
            return '\\' . $node->extends->toString();
        }

        // Интерфейс может расширять несколько интерфейсов; первый пишем как
        // родителя, остальные попадают в список интерфейсов.
        if ($node instanceof Interface_ && $node->extends !== []) {
            return '\\' . $node->extends[0]->toString();
        }

        return null;
    }

    /** @return list<string> */
    private function interfacesOf(ClassLike $node): array
    {
        $names = [];

        if ($node instanceof Class_) {
            $names = $node->implements;
        } elseif ($node instanceof Interface_) {
            $names = array_slice($node->extends, 1);
        } elseif ($node instanceof Enum_) {
            $names = $node->implements;
        }

        return array_values(array_map(
            static fn(Node\Name $n): string => '\\' . $n->toString(),
            $names,
        ));
    }

    /** @return list<string> */
    private function traitsOf(ClassLike $node): array
    {
        $names = [];

        foreach ($node->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                $names[] = '\\' . $trait->toString();
            }
        }

        return $names;
    }

    /** @return list<ExtractedMethod> */
    private function methodsOf(ClassLike $node): array
    {
        $result = [];

        foreach ($node->getMethods() as $method) {
            $result[] = $this->methods->extract($method);
        }

        return $result;
    }

    /** @return list<ExtractedConstant> */
    private function constantsOf(ClassLike $node): array
    {
        $result = [];
        $renderer = new SignatureRenderer();

        foreach ($node->getConstants() as $statement) {
            $visibility = match (true) {
                $statement->isPrivate() => 'private',
                $statement->isProtected() => 'protected',
                default => 'public',
            };
            $doc = $statement->getDocComment()?->getText();

            foreach ($statement->consts as $constant) {
                $result[] = new ExtractedConstant(
                    name: $constant->name->toString(),
                    visibility: $visibility,
                    isFinal: $statement->isFinal(),
                    valueRepr: $renderer->renderExpression($constant->value),
                    docComment: $doc,
                    line: $constant->getStartLine(),
                );
            }
        }

        return $result;
    }
}
