<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;

/** Точки вызова, извлечённые из AST без grep и текстовых эвристик. */
final class UsageVisitor extends NodeVisitorAbstract
{
    /** @var list<ExtractedUsage> */
    private array $usages = [];
    /** @var list<?string> */
    private array $classStack = [];
    /** @var list<?string> */
    private array $memberStack = [];
    private ?string $contextFqn = null;
    private ?string $contextMember = null;

    public function beforeTraverse(array $nodes): ?array
    {
        $this->usages = [];
        $this->classStack = [];
        $this->memberStack = [];
        $this->contextFqn = null;
        $this->contextMember = null;

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof ClassLike && $node->name !== null) {
            $this->classStack[] = $this->contextFqn;
            $resolved = $node->namespacedName?->toString() ?? $node->name->toString();
            $this->contextFqn = '\\' . ltrim($resolved, '\\');
        }

        if ($node instanceof ClassMethod) {
            $this->memberStack[] = $this->contextMember;
            $this->contextMember = $node->name->toString();
        } elseif ($node instanceof Function_) {
            $this->memberStack[] = $this->contextMember;
            $resolved = $node->namespacedName?->toString() ?? $node->name->toString();
            $this->contextMember = '\\' . ltrim($resolved, '\\');
        }

        if ($node instanceof FuncCall && $node->name instanceof Node\Name) {
            $namespaced = $node->name->getAttribute('namespacedName');
            $resolved = $namespaced instanceof Node\Name ? $namespaced : $node->name;
            $target = '\\' . ltrim($resolved->toString(), '\\');
            $parts = explode('\\', $target);
            // Неквалифицированная функция в namespace имеет fallback на
            // глобальную, поэтому namespacedName — кандидат, а не доказанная цель.
            $this->add('function', $target, (string)end($parts), $namespaced instanceof Node\Name, $node);
        } elseif (
            $node instanceof StaticCall
            && $node->name instanceof Node\Identifier
        ) {
            $class = $node->class instanceof Node\Name ? $node->class->toString() : null;
            $classLower = $class === null ? null : mb_strtolower($class);
            $lateBound = in_array($classLower, ['self', 'static'], true);
            $target = $class === null
                ? null
                : ($lateBound && $this->contextFqn !== null
                    ? $this->contextFqn
                    : '\\' . ltrim($class, '\\'));
            $this->add(
                'static_method',
                $target,
                $node->name->toString(),
                $class === null || in_array($classLower, ['static', 'parent'], true),
                $node,
            );
        } elseif (
            ($node instanceof MethodCall || $node instanceof NullsafeMethodCall)
            && $node->name instanceof Node\Identifier
        ) {
            $this->add('method', null, $node->name->toString(), true, $node);
        }

        return null;
    }

    public function leaveNode(Node $node): ?int
    {
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            $this->contextMember = array_pop($this->memberStack);
        }

        if ($node instanceof ClassLike && $node->name !== null) {
            $this->contextFqn = array_pop($this->classStack);
        }

        return null;
    }

    /** @return list<ExtractedUsage> */
    public function usages(): array
    {
        return $this->usages;
    }

    private function add(
        string $kind,
        ?string $targetFqn,
        string $member,
        bool $dynamic,
        Node $node,
    ): void {
        $this->usages[] = new ExtractedUsage(
            kind: $kind,
            targetFqn: $targetFqn,
            memberName: $member,
            contextFqn: $this->contextFqn,
            contextMember: $this->contextMember,
            isDynamic: $dynamic,
            line: $node->getStartLine(),
        );
    }
}
