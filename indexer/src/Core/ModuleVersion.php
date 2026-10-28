<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Core;

/**
 * Версия одного модуля.
 *
 * У Битрикса нет единой версии: модули версионируются независимо и расходятся
 * на целый мажор. В целевом ядре при SM_VERSION 25.100.300 одновременно живут
 * crm 24.1300.0, rest 25.100.200, bizproc 24.900.0 и ui 25.0.0.
 */
final readonly class ModuleVersion
{
    public function __construct(
        public string $code,
        public ?string $version,
        public ?string $versionDate,
        public VersionSource $source,
        public string $relPath,
    ) {
    }

    public static function unknown(string $code, string $relPath): self
    {
        return new self($code, null, null, VersionSource::Unknown, $relPath);
    }

    public function isKnown(): bool
    {
        return $this->version !== null;
    }

    /** Человекочитаемая атрибуция для вывода: `crm@24.1300.0` либо `crm@версия неизвестна`. */
    public function label(): string
    {
        return $this->code . '@' . ($this->version ?? 'версия неизвестна');
    }
}
