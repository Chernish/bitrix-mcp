<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract\Orm;

final readonly class ExtractedOrmField
{
    public function __construct(
        public string $name,
        public ?string $dataType,
        public bool $isPrimary,
        public bool $isRequired,
        public bool $isAutocomplete,
        /** Текстовое представление значения по умолчанию, не вычисленное значение. */
        public ?string $defaultRepr,
        /**
         * false, если значение задано замыканием и статически не вычислимо.
         * Отличать это от «значения нет» обязательно: молчаливое превращение
         * невычислимого в отсутствующее — потеря данных (Принцип III).
         */
        public bool $isDefaultResolved,
        public ?string $referenceTargetFqn,
        public ?string $joinCondition,
        public ?string $valuesRepr,
    ) {
    }

    public static function simple(string $name, ?string $dataType): self
    {
        return new self($name, $dataType, false, false, false, null, true, null, null, null);
    }
}
