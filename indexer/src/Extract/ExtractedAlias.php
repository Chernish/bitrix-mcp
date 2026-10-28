<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

/**
 * Псевдоним класса, объявленный через class_alias.
 *
 * Индексируется отдельно от символов, потому что объявления с именем псевдонима
 * в ядре нет. \Bitrix\Sale\OrderTable — именно та форма, которую напишет агент,
 * но класс объявлен как \Bitrix\Sale\Internals\OrderTable (R-006).
 */
final readonly class ExtractedAlias
{
    public function __construct(
        public string $aliasFqn,
        public string $targetFqn,
        public int $line,
    ) {
    }

    public function aliasShortName(): string
    {
        $parts = explode('\\', trim($this->aliasFqn, '\\'));

        return $parts[count($parts) - 1];
    }
}
