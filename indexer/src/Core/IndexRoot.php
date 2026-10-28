<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Core;

/**
 * Корень индексации.
 *
 * Отдельный тип, а не строка, потому что модель владения сущностями обязана
 * различать корни с самого начала. FR-014 требует, чтобы добавление корня
 * (например, кастомного кода проекта в каталоге local) было настройкой,
 * а не переработкой. Переход от «один корень» к «список» после того, как
 * на единственный корень начали опираться все визиторы, — это уже переработка.
 */
final readonly class IndexRoot
{
    public function __construct(
        public string $path,
        public string $label,
        public bool $isCore,
    ) {
    }

    public static function core(string $path): self
    {
        return new self($path, 'core', true);
    }

    public static function extra(string $path, string $label): self
    {
        return new self($path, $label, false);
    }

    /**
     * Путь относительно этого корня. Индекс хранит относительные пути,
     * что и делает файл переносимым между машинами (SC-006).
     */
    public function relativize(string $absolutePath): string
    {
        $prefix = rtrim($this->path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (str_starts_with($absolutePath, $prefix)) {
            return substr($absolutePath, strlen($prefix));
        }

        return $absolutePath;
    }
}
