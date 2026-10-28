<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;

/** Извлекает только адресуемые функции; closure и arrow function не индексируются. */
final class FunctionVisitor extends NodeVisitorAbstract
{
    /** @var list<ExtractedFunction> */
    private array $functions = [];

    public function __construct(private readonly SignatureRenderer $renderer = new SignatureRenderer())
    {
    }

    public function beforeTraverse(array $nodes): ?array
    {
        $this->functions = [];

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if (!$node instanceof Function_) {
            return null;
        }

        $params = [];
        $position = 0;

        foreach ($node->params as $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                $position++;
                continue;
            }

            $params[] = new ExtractedParameter(
                position: $position++,
                name: $param->var->name,
                type: SignatureRenderer::renderType($param->type),
                defaultRepr: $param->default === null
                    ? null
                    : $this->renderer->renderExpression($param->default),
                byRef: $param->byRef,
                variadic: $param->variadic,
            );
        }

        $name = $node->name->toString();
        $resolved = $node->namespacedName?->toString() ?? $name;
        $returnType = SignatureRenderer::renderType($node->returnType);
        $doc = $node->getDocComment()?->getText();
        [$deprecated, ] = DeprecationDetector::detect($doc);

        $this->functions[] = new ExtractedFunction(
            fqn: '\\' . ltrim($resolved, '\\'),
            shortName: $name,
            signature: $this->renderer->renderFunction($name, $params, $returnType),
            returnType: $returnType,
            docComment: $doc,
            isDeprecated: $deprecated,
            line: $node->getStartLine(),
        );

        return null;
    }

    /** @return list<ExtractedFunction> */
    public function functions(): array
    {
        return $this->functions;
    }
}
