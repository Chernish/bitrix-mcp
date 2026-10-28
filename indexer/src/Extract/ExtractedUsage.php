<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

final readonly class ExtractedUsage
{
    public function __construct(
        public string $kind,
        public ?string $targetFqn,
        public string $memberName,
        public ?string $contextFqn,
        public ?string $contextMember,
        public bool $isDynamic,
        public int $line,
    ) {
    }
}
