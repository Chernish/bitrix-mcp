<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

use BitrixMcp\Indexer\Extract\Component\ExtractedComponent;
use BitrixMcp\Indexer\Extract\Event\ExtractedEvent;

/**
 * Всё, что извлечено из одного файла.
 */
final readonly class FileExtraction
{
    /**
     * @param list<ExtractedSymbol> $symbols
     * @param list<ExtractedAlias> $aliases
     * @param list<ExtractedEvent> $events
     * @param list<ExtractedFunction> $functions
     * @param list<ExtractedUsage> $usages
     * @param list<array{name: string, handler: string|null, line: int}> $restMethods
     * @param list<string> $dependencies коды модулей, подключаемых этим файлом
     */
    public function __construct(
        public array $symbols = [],
        public array $aliases = [],
        public array $events = [],
        public array $functions = [],
        public array $usages = [],
        public array $restMethods = [],
        public array $dependencies = [],
        public ?ExtractedComponent $component = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->symbols === []
            && $this->aliases === []
            && $this->events === []
            && $this->functions === []
            && $this->usages === []
            && $this->restMethods === []
            && $this->dependencies === []
            && $this->component === null;
    }
}
