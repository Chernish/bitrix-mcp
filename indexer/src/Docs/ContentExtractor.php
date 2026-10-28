<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Docs;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Извлечение текста страницы документации.
 *
 * Здесь регулярные выражения и разбор разметки допустимы: это неструктурная
 * проза, а не синтаксис PHP. Принцип III запрещает эвристики для структурных
 * фактов о коде, а не для текста статьи.
 */
final class ContentExtractor
{
    /** Элементы, не несущие содержания статьи. */
    private const STRIP = ['script', 'style', 'nav', 'header', 'footer', 'aside', 'noscript'];

    /**
     * @return array{
     *   title: string|null,
     *   content: string,
     *   versionMentions: list<string>,
     *   versionNotes: list<array{
     *     version: string,
     *     subject_kind: string,
     *     module?: string,
     *     subject?: string,
     *     heading?: string,
     *     statement: string
     *   }>
     * }
     */
    public function extract(string $html): array
    {
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);

        foreach (self::STRIP as $tag) {
            $nodes = $doc->getElementsByTagName($tag);

            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);

                if ($node instanceof \DOMNode && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        // Diplodoc дублирует текст каждого заголовка внутри скрытой ссылки для
        // screen reader. В полнотекстовом индексе это выглядело как
        // «HTTP-клиентHTTP-клиент» и искусственно меняло bm25.
        $hidden = $xpath->query('//*[@data-no-index="true"]');
        if ($hidden !== false) {
            for ($i = $hidden->length - 1; $i >= 0; $i--) {
                $node = $hidden->item($i);

                if ($node instanceof \DOMNode && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $title = $this->firstText($xpath, '//h1') ?? $this->firstText($xpath, '//title');

        // Внешний <main> содержит навигацию и мини-TOC. Тело статьи у
        // Diplodoc имеет отдельный класс; fallback сохраняет совместимость с
        // простыми HTML-фикстурами и возможной сменой шаблона сайта.
        $article = $xpath->query(
            '//*[contains(concat(" ", normalize-space(@class), " "), " dc-doc-page__body ")]'
        );
        $main = $xpath->query('//main');
        $rootCandidate = ($article !== false && $article->length > 0)
            ? $article->item(0)
            : (($main !== false && $main->length > 0) ? $main->item(0) : $doc->documentElement);
        $root = $rootCandidate instanceof \DOMNode ? $rootCandidate : null;

        $text = $root instanceof \DOMNode ? (string)$root->textContent : '';
        $text = $this->normalize($text);

        $versionNotes = (new VersionNoteParser())->parse($this->versionBlocks($xpath, $root));

        return [
            'title' => $title,
            'content' => $text,
            // Старое поле оставлено в результате экстрактора для совместимости
            // тестов/потребителей; в БД записывается структурированный JSON.
            'versionMentions' => array_values(array_unique(array_column($versionNotes, 'version'))),
            'versionNotes' => $versionNotes,
        ];
    }

    private function firstText(DOMXPath $xpath, string $query): ?string
    {
        $nodes = $xpath->query($query);

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $node = $nodes->item(0);
        $value = $this->normalize($node instanceof \DOMNode ? (string)$node->textContent : '');

        return $value === '' ? null : $value;
    }

    private function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\R\s*/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    /** @return list<array{text: string, heading: string|null}> */
    private function versionBlocks(DOMXPath $xpath, ?\DOMNode $root): array
    {
        if ($root === null) {
            return [];
        }

        $nodes = $xpath->query('.//*[self::p or self::li]', $root);

        if ($nodes === false) {
            return [];
        }

        $result = [];

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMNode) {
                continue;
            }

            $text = $this->normalize((string)$node->textContent);
            if ($text === '') {
                continue;
            }

            $result[] = ['text' => $text, 'heading' => $this->nearestHeading($xpath, $node)];
        }

        return $result;
    }

    private function nearestHeading(DOMXPath $xpath, \DOMNode $node): ?string
    {
        $headings = $xpath->query('preceding::*[self::h1 or self::h2 or self::h3 or self::h4][1]', $node);

        if ($headings === false || $headings->length === 0) {
            return null;
        }

        $heading = $headings->item(0);
        $text = $this->normalize($heading instanceof \DOMNode ? (string)$heading->textContent : '');

        return $text === '' ? null : $text;
    }
}
