<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract\Orm;

/**
 * ORM-сущность: привязка класса к таблице и карта её полей.
 *
 * Неполнота выражена явно и всегда с причиной. Схема, отданная как полная,
 * когда часть полей не извлеклась, опаснее отсутствия схемы: агент напишет
 * код против неполной картины и не получит никакого сигнала.
 */
final readonly class ExtractedOrmEntity
{
    /** @param list<ExtractedOrmField> $fields */
    public function __construct(
        public ?string $tableName,
        /** literal | unresolved | inherited */
        public string $tableNameSource,
        /** array | fluent | mixed | unresolved */
        public string $extractionStyle,
        public bool $isComplete,
        public ?string $incompleteReason,
        public array $fields,
    ) {
    }
}
