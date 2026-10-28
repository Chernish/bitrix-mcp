<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

/**
 * Признак устаревания из PHPDoc.
 *
 * Это ФАКТ, прочитанный из документирующего комментария, в отличие от
 * эвристического ApiStyle. Разделение обязательно: у полей разная достоверность,
 * и смешение сделало бы догадку неотличимой от факта (Принцип III).
 *
 * Регулярное выражение здесь допустимо: PHPDoc — неструктурная проза, а не
 * синтаксическая конструкция PHP. Принцип III запрещает эвристики для
 * структурных фактов, а не для текста комментария.
 */
final class DeprecationDetector
{
    /**
     * @return array{0: bool, 1: string|null} признак и текст пометки
     */
    public static function detect(?string $docComment): array
    {
        if ($docComment === null || $docComment === '') {
            return [false, null];
        }

        if (preg_match('/@deprecated\s*(.*)$/mi', $docComment, $m) !== 1) {
            return [false, null];
        }

        $note = trim($m[1]);
        $note = trim($note, "* \t\n\r");

        return [true, $note !== '' ? $note : null];
    }

    /**
     * Первая содержательная строка PHPDoc — то, что уходит агенту как
     * краткое описание. Целый блок отдавать нельзя: он вытесняет
     * из контекста саму задачу (Принцип IV).
     */
    public static function summary(?string $docComment, int $maxLength = 200): ?string
    {
        if ($docComment === null || $docComment === '') {
            return null;
        }

        $lines = preg_split('/\R/', $docComment) ?: [];

        foreach ($lines as $line) {
            $clean = trim($line);
            $clean = preg_replace('#^(/\*\*|\*/|\*)\s*#', '', $clean) ?? '';
            $clean = trim($clean);

            if ($clean === '' || str_starts_with($clean, '@')) {
                continue;
            }

            return mb_strlen($clean) > $maxLength
                ? mb_substr($clean, 0, $maxLength - 1) . '…'
                : $clean;
        }

        return null;
    }
}
