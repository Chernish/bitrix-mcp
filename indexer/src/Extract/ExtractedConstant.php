<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

final readonly class ExtractedConstant
{
    public function __construct(
        public string $name,
        public string $visibility,
        public bool $isFinal,
        public string $valueRepr,
        public ?string $docComment,
        public int $line,
    ) {
    }
}
