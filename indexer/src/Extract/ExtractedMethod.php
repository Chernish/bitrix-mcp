<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

final readonly class ExtractedMethod
{
    /** @param list<ExtractedParameter> $parameters */
    public function __construct(
        public string $name,
        public string $visibility,
        public bool $isStatic,
        public bool $isAbstract,
        public bool $isFinal,
        public ?string $returnType,
        public string $signature,
        public ?string $docComment,
        public bool $isDeprecated,
        public int $line,
        public array $parameters,
    ) {
    }
}
