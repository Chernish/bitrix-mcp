<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Docs;

final readonly class DocsRepositorySnapshot
{
    public function __construct(
        public string $path,
        public string $revision,
        public string $source,
    ) {
    }
}
