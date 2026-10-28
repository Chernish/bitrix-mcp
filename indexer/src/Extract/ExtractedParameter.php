<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

final readonly class ExtractedParameter
{
    public function __construct(
        public int $position,
        public string $name,
        public ?string $type,
        /**
         * Исходное текстовое представление значения по умолчанию.
         * НЕ вычисленное значение: вычисление потребовало бы выполнения кода,
         * что запрещено Принципом VI.
         */
        public ?string $defaultRepr,
        public bool $byRef,
        public bool $variadic,
    ) {
    }
}
