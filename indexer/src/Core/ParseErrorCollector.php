<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Core;

/**
 * Накопитель ошибок разбора.
 *
 * Ошибка одного файла из 67 836 не имеет права уронить проход (Принцип V).
 * Но и проглотить её нельзя: если непарсибельные файлы просто пропускаются
 * молча, отсутствие символа в индексе становится неотличимо от его отсутствия
 * в ядре — и Принцип I начинает врать.
 *
 * Поэтому ошибки собираются, считаются и остаются видимыми в отчёте
 * и в `snapshot.parse_error_count`.
 */
final class ParseErrorCollector
{
    /** @var list<array{path: string, message: string, line: int|null}> */
    private array $errors = [];

    public function record(string $relPath, string $message, ?int $line = null): void
    {
        $this->errors[] = ['path' => $relPath, 'message' => $message, 'line' => $line];
    }

    public function count(): int
    {
        return count($this->errors);
    }

    /** @return list<array{path: string, message: string, line: int|null}> */
    public function all(): array
    {
        return $this->errors;
    }

    /** @return list<array{path: string, message: string, line: int|null}> */
    public function sample(int $limit = 8): array
    {
        return array_slice($this->errors, 0, $limit);
    }

    /**
     * Доля непарсибельных файлов. SC-009 задаёт порог в 1%: превышение
     * подлежит расследованию, а не молчаливому принятию.
     */
    public function share(int $totalFiles): float
    {
        return $totalFiles > 0 ? $this->count() / $totalFiles : 0.0;
    }

    public function exceedsThreshold(int $totalFiles, float $threshold = 0.01): bool
    {
        return $this->share($totalFiles) > $threshold;
    }
}
