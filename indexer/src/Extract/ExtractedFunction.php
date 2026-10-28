<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

final readonly class ExtractedFunction
{
    public function __construct(
        public string $fqn,
        public string $shortName,
        public string $signature,
        public ?string $returnType,
        public ?string $docComment,
        public bool $isDeprecated,
        public int $line,
    ) {
    }
}
