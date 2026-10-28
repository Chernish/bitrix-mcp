<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Core;

/**
 * Версия сборки: константы SM_VERSION и SM_VERSION_DATE из главного модуля.
 *
 * Описывает поставку в целом и НЕ заменяет версию отдельного модуля.
 * Служит дешёвым признаком рассинхрона: штатное обновление Битрикса
 * всегда меняет SM_VERSION (research.md, R-009).
 */
final readonly class BuildVersion
{
    public function __construct(
        public string $smVersion,
        public ?string $smVersionDate,
    ) {
    }
}
