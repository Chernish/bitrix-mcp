<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

final readonly class ExtractedTraitAdaptation
{
    public function __construct(
        public string $traitFqn,
        public string $methodName,
        public ?string $alias,
        public ?string $visibility,
        public bool $excluded,
    ) {
    }
}
