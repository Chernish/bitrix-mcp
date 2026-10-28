<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Core;

/**
 * Файл, встреченный при обходе, вместе с обязательной атрибуцией к модулю.
 *
 * `moduleCode` не допускает null намеренно: обход устроен так, что файл вне
 * модуля просто не попадает в выдачу. Это делает нарушение Принципа II
 * невозможным на уровне типов, а не проверок.
 */
final readonly class WalkedFile
{
    public function __construct(
        public IndexRoot $root,
        public string $absPath,
        public string $relPath,
        public string $moduleCode,
        public int $size,
        public int $mtime,
    ) {
    }

    public function readContents(): ?string
    {
        // Только чтение: индексатор никогда не изменяет файлы ядра (Принцип VI).
        $code = @file_get_contents($this->absPath);

        return $code === false ? null : $code;
    }

    public static function sha256(string $contents): string
    {
        return hash('sha256', $contents);
    }

    /**
     * Признак короткого открывающего тега.
     *
     * Хранится не ради статистики: при выключенном short_open_tag такие файлы
     * разбираются как HTML без ошибки, и признак — единственный способ
     * отличить «в файле нет объявлений» от «файл проглочен» (R-001).
     */
    public static function hasShortOpenTag(string $contents): bool
    {
        return preg_match('/^\s*<\?(?!php|=)/', $contents) === 1;
    }
}
