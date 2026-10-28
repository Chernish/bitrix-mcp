<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Docs;

/**
 * Извлекает из прозы структурированные ограничения «доступно с версии».
 *
 * Регулярные выражения здесь допустимы: разбирается человеческий текст, а не
 * факты о PHP API. Заголовок передаётся отдельно, чтобы число версии не
 * теряло контекст возможности, к которой оно относится.
 */
final class VersionNoteParser
{
    public const FORMAT_SCHEMA = 4;

    /**
     * @param iterable<array{text: string, heading?: string|null}> $blocks
     * @return list<array{
     *   version: string,
     *   subject_kind: string,
     *   module?: string,
     *   subject?: string,
     *   heading?: string,
     *   statement: string
     * }>
     */
    public function parse(iterable $blocks): array
    {
        $pattern = '/(?:(?:начиная\s+)?с\s+версии|доступ\p{L}*\s+с(?:\s+версии)?)'
            . '\s+(?:[\p{L}\p{N}_.-]+\s+){0,6}?`?(\d+\.\d+(?:\.\d+)?)`?/ui';
        $result = [];
        $seen = [];

        foreach ($blocks as $block) {
            $text = $this->normalize($block['text']);
            if ($text === '') {
                continue;
            }

            $statements = preg_split('/(?<=[.!?])\s+|\R+/u', $text) ?: [];
            foreach ($statements as $statement) {
                $statement = trim($statement);
                $matchCount = preg_match_all(
                    $pattern,
                    $statement,
                    $matches,
                    PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
                );

                if ($statement === '' || $matchCount === false || $matchCount === 0) {
                    continue;
                }

                foreach ($matches as $match) {
                    $versionMatch = $match[1];
                    $version = $versionMatch[0];
                    $context = $this->contextAround($statement, $versionMatch[1]);
                    $subject = $this->classifyVersionSubject($context);
                    $key = implode('|', [$version, $subject['module'] ?? '', $statement]);

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $note = [
                        'version' => $version,
                        'subject_kind' => $subject['kind'],
                        'statement' => mb_substr($statement, 0, 500),
                    ];

                    if (isset($subject['module'])) {
                        $note['module'] = $subject['module'];
                    }
                    if (isset($subject['label'])) {
                        $note['subject'] = $subject['label'];
                    }

                    $heading = $block['heading'] ?? null;
                    if (is_string($heading) && $heading !== '') {
                        $note['heading'] = $heading;
                    }

                    $result[] = $note;
                }
            }
        }

        return $result;
    }

    /** @return array{kind: string, module?: string, label?: string} */
    private function classifyVersionSubject(string $statement): array
    {
        $lower = mb_strtolower($statement);

        if (preg_match('/\bphp\s*\d/u', $lower) === 1) {
            return ['kind' => 'php', 'label' => 'PHP'];
        }

        if (preg_match('/\bbitrixvm\s*\d/u', $lower) === 1) {
            return ['kind' => 'bitrixvm', 'label' => 'BitrixVM'];
        }

        if (preg_match('/главн\p{L}*\s+модул|модул\p{L}*\s+main|\bmain\b/u', $lower) === 1) {
            return ['kind' => 'module', 'module' => 'main', 'label' => 'Главный модуль'];
        }

        if (preg_match('/модул\p{L}*\s+ui\b/u', $lower) === 1) {
            return ['kind' => 'module', 'module' => 'ui', 'label' => 'UI'];
        }

        if (preg_match('/модул\p{L}*\s+проактивн\p{L}*\s+защит/u', $lower) === 1) {
            return ['kind' => 'module', 'module' => 'security', 'label' => 'Проактивная защита'];
        }

        if (preg_match('/модул\p{L}*\s+([\p{L}][\p{L}\d_.-]*)/u', $lower, $match) === 1) {
            return ['kind' => 'module', 'label' => $match[1]];
        }

        return ['kind' => 'unknown'];
    }

    private function contextAround(string $statement, int $versionByteOffset): string
    {
        $characterOffset = mb_strlen(substr($statement, 0, $versionByteOffset));
        $start = max(0, $characterOffset - 100);

        return mb_substr($statement, $start, 220);
    }

    private function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
