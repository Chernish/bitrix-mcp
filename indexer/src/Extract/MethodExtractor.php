<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;

/**
 * Извлечение метода и его параметров из узла AST.
 */
final class MethodExtractor
{
    public function __construct(
        private readonly SignatureRenderer $renderer = new SignatureRenderer(),
    ) {
    }

    public function extract(ClassMethod $node): ExtractedMethod
    {
        $params = $this->extractParameters($node);
        $doc = $node->getDocComment()?->getText();
        [$isDeprecated, ] = DeprecationDetector::detect($doc);

        $visibility = match (true) {
            $node->isPrivate() => 'private',
            $node->isProtected() => 'protected',
            default => 'public',
        };

        $returnType = SignatureRenderer::renderType($node->returnType);

        return new ExtractedMethod(
            name: $node->name->toString(),
            visibility: $visibility,
            isStatic: $node->isStatic(),
            isAbstract: $node->isAbstract(),
            isFinal: $node->isFinal(),
            returnType: $returnType,
            signature: $this->renderer->render(
                $node->name->toString(),
                $visibility,
                $node->isStatic(),
                $node->isAbstract(),
                $params,
                $returnType,
            ),
            docComment: $doc,
            isDeprecated: $isDeprecated,
            line: $node->getStartLine(),
            parameters: $params,
        );
    }

    /** @return list<ExtractedParameter> */
    private function extractParameters(ClassMethod $node): array
    {
        $result = [];
        $position = 0;

        foreach ($node->params as $param) {
            if (!$param->var instanceof Node\Expr\Variable || !is_string($param->var->name)) {
                // Переменная-переменная в объявлении параметра синтаксически
                // невозможна, но узел допускает такую форму. Пропуск с сохранением
                // позиции лучше, чем выдумывание имени.
                $position++;
                continue;
            }

            $result[] = new ExtractedParameter(
                position: $position++,
                name: $param->var->name,
                type: SignatureRenderer::renderType($param->type),
                defaultRepr: $param->default !== null
                    ? $this->renderer->renderExpression($param->default)
                    : null,
                byRef: $param->byRef,
                variadic: $param->variadic,
            );
        }

        return $result;
    }
}
