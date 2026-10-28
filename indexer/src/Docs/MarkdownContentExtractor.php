<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Docs;

/** Извлекает поисковый текст и метаданные напрямую из исходников Diplodoc Markdown. */
final class MarkdownContentExtractor
{
    /**
     * @return array{
     *   title: string|null,
     *   content: string,
     *   versionMentions: list<string>,
     *   versionNotes: list<array<string, string>>
     * }
     */
    public function extract(string $markdown): array
    {
        [$frontMatter, $body] = $this->frontMatter($markdown);
        $title = $this->title($frontMatter, $body);
        $blocks = $this->blocks($body);
        $versionNotes = (new VersionNoteParser())->parse($blocks);

        return [
            'title' => $title,
            'content' => $this->plainText($body),
            'versionMentions' => array_values(array_unique(array_column($versionNotes, 'version'))),
            'versionNotes' => $versionNotes,
        ];
    }

    /** @return array{0: string, 1: string} */
    private function frontMatter(string $markdown): array
    {
        if (preg_match('/\A---\R(.*?)\R---\R?(.*)\z/su', $markdown, $match) !== 1) {
            return ['', $markdown];
        }

        return [$match[1], $match[2]];
    }

    private function title(string $frontMatter, string $body): ?string
    {
        if (preg_match('/^title:\s*(.+)$/miu', $frontMatter, $match) === 1) {
            $title = trim($match[1], " \t\n\r\0\x0B\"'");
            if ($title !== '') {
                return $title;
            }
        }

        if (preg_match('/^#\s+(.+)$/mu', $body, $match) === 1) {
            return trim($match[1]);
        }

        return null;
    }

    /** @return list<array{text: string, heading: string|null}> */
    private function blocks(string $body): array
    {
        $heading = null;
        $paragraph = [];
        $blocks = [];

        $flush = static function () use (&$paragraph, &$blocks, &$heading): void {
            $text = trim(implode(' ', $paragraph));
            if ($text !== '') {
                $blocks[] = ['text' => $text, 'heading' => $heading];
            }
            $paragraph = [];
        };

        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            if (preg_match('/^#{1,6}\s+(.+)$/u', trim($line), $match) === 1) {
                $flush();
                $heading = trim($match[1]);
                continue;
            }

            if (trim($line) === '' || preg_match('/^\s*{%.*%}\s*$/u', $line) === 1) {
                $flush();
                continue;
            }

            $paragraph[] = preg_replace('/^\s*(?:[-*+]\s+|\d+\.\s+)/u', '', trim($line)) ?? trim($line);
        }

        $flush();

        return $blocks;
    }

    private function plainText(string $body): string
    {
        $text = preg_replace('/^\s*{%.*?%}\s*$/mu', '', $body) ?? $body;
        $text = preg_replace('/^```[^\r\n]*$/mu', '', $text) ?? $text;
        $text = preg_replace('/^#{1,6}\s+/mu', '', $text) ?? $text;
        $text = preg_replace('/!\[([^]]*)]\([^)]+\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/\[([^]]+)]\([^)]+\)/u', '$1', $text) ?? $text;
        $text = preg_replace('/[`*_~]+/u', '', $text) ?? $text;
        $text = strip_tags($text);
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\R\s*/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
