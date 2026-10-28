<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract\Component;

final readonly class ExtractedComponentParam
{
    public function __construct(
        public string $code,
        public ?string $type,
        public ?string $defaultRepr,
        /**
         * false, если значение вычисляется в рантайме — через GetMessage(),
         * COption::GetOptionString() или запрос к базе. Это ожидаемое
         * состояние, а не ошибка: см. Принцип VI.
         */
        public bool $isDefaultResolved,
        public ?string $parentGroup,
        public bool $multiple,
        public ?string $valuesRepr,
    ) {
    }
}
