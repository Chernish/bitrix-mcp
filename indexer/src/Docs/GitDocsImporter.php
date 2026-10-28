<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Docs;

use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/** Индексирует pages/*.md без промежуточного HTML и сетевого обхода сайта. */
final class GitDocsImporter
{
    public const BASE_URL = 'https://docs.1c-bitrix.ru';

    public function __construct(private readonly MarkdownContentExtractor $extractor = new MarkdownContentExtractor())
    {
    }

    /** @return array{pages: int, changed: int, unchanged: int, removed: int} */
    public function import(PDO $pdo, DocsRepositorySnapshot $snapshot, bool $force = false): array
    {
        $files = $this->markdownFiles($snapshot->path . DIRECTORY_SEPARATOR . 'pages');
        if ($files === []) {
            throw new RuntimeException('в репозитории документации не найдено pages/*.md');
        }

        $known = $this->knownHashes($pdo);
        $urls = [];
        $changed = 0;
        $unchanged = 0;
        $now = gmdate('c');
        $insert = $pdo->prepare(
            'INSERT INTO doc_page
                (url, section, title, content, fetched_at, etag, content_sha256,
                 version_mentions, links, source_type, source_ref, source_revision)
             VALUES (:url, :section, :title, :content, :at, NULL, :sha,
                     :versions, NULL, \'git\', :source_ref, :revision)
             ON CONFLICT (url) DO UPDATE SET
                section = excluded.section, title = excluded.title, content = excluded.content,
                fetched_at = excluded.fetched_at, etag = NULL,
                content_sha256 = excluded.content_sha256,
                version_mentions = excluded.version_mentions, links = NULL,
                source_type = excluded.source_type, source_ref = excluded.source_ref,
                source_revision = excluded.source_revision'
        );

        foreach ($files as $file) {
            $sourcePath = $this->relativePath($snapshot->path, $file->getPathname());
            $url = self::BASE_URL . '/' . substr($sourcePath, 0, -3) . '.html';
            $urls[] = $url;
            $markdown = file_get_contents($file->getPathname());
            if (!is_string($markdown)) {
                throw new RuntimeException("не удалось прочитать {$sourcePath}");
            }

            $sha = hash('sha256', $markdown);
            $sameContent = ($known[$url]['sha'] ?? null) === $sha;
            $sameRevision = ($known[$url]['revision'] ?? null) === $snapshot->revision;
            $sameExtractor = ($known[$url]['format'] ?? null) === VersionNoteParser::FORMAT_SCHEMA;
            if (!$force && $sameContent && $sameRevision && $sameExtractor) {
                $unchanged++;
                continue;
            }

            $parsed = $this->extractor->extract($markdown);
            if (mb_strlen($parsed['content']) < 20) {
                continue;
            }

            $insert->execute([
                ':url' => $url,
                ':section' => $this->section($sourcePath),
                ':title' => $parsed['title'],
                ':content' => $parsed['content'],
                ':at' => $now,
                ':sha' => $sha,
                ':versions' => json_encode(
                    ['schema' => VersionNoteParser::FORMAT_SCHEMA, 'notes' => $parsed['versionNotes']],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
                ':source_ref' => $sourcePath,
                ':revision' => $snapshot->revision,
            ]);
            $changed++;
        }

        $removed = $this->removeMissingPages($pdo, $urls);

        return ['pages' => count($urls), 'changed' => $changed, 'unchanged' => $unchanged, 'removed' => $removed];
    }

    /** @return list<SplFileInfo> */
    private function markdownFiles(string $pagesPath): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($pagesPath, FilesystemIterator::SKIP_DOTS),
        );
        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'md') {
                $files[] = $file;
            }
        }

        usort($files, static fn(SplFileInfo $a, SplFileInfo $b): int => $a->getPathname() <=> $b->getPathname());

        return $files;
    }

    /** @return array<string, array{sha: string, revision: string|null, format: int|null}> */
    private function knownHashes(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT url, content_sha256, source_revision, version_mentions FROM doc_page'
        );
        $known = [];
        foreach ($rows === false ? [] : $rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $known[(string)$row['url']] = [
                'sha' => (string)$row['content_sha256'],
                'revision' => is_string($row['source_revision']) ? $row['source_revision'] : null,
                'format' => $this->versionFormat($row['version_mentions'] ?? null),
            ];
        }

        return $known;
    }

    private function versionFormat(mixed $raw): ?int
    {
        if (!is_string($raw)) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && is_int($decoded['schema'] ?? null)
            ? $decoded['schema']
            : null;
    }

    /** @param list<string> $urls */
    private function removeMissingPages(PDO $pdo, array $urls): int
    {
        $placeholders = implode(',', array_fill(0, count($urls), '?'));
        $stmt = $pdo->prepare(
            "DELETE FROM doc_page WHERE url LIKE ? AND url NOT IN ({$placeholders})"
        );
        // Git-снимок должен быть самодостаточным: не оставляем смешанные строки
        // от прежнего web-обхода, иначе общая revision не описывает весь набор.
        $stmt->execute([self::BASE_URL . '/%', ...$urls]);

        return $stmt->rowCount();
    }

    private function relativePath(string $root, string $path): string
    {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen(rtrim($root, DIRECTORY_SEPARATOR)) + 1));
    }

    private function section(string $sourcePath): string
    {
        $parts = explode('/', $sourcePath);

        return $parts[1] ?? 'root';
    }
}
