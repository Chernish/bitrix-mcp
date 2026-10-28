<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Storage;

use PDO;
use Throwable;

/**
 * Сохранение собранной документации при полной пересборке индекса.
 *
 * Команда `build` создаёт индекс с нуля, и без этого шага она уничтожала бы
 * страницы документации вместе с кодом. Это неверно по существу: документация
 * живёт на стороннем сайте и меняется независимо от ядра. Разработчик,
 * обновивший Битрикс и пересобравший индекс, терял бы 155 страниц и был
 * вынужден ждать восьмидесяти секунд сетевого обхода без всякой причины.
 *
 * Объём переноса невелик (сотни строк), поэтому он делается через память
 * без временных файлов.
 */
final class DocsPreserver
{
    /** @var list<array<string, mixed>> */
    private array $pages = [];

    private ?string $crawledAt = null;

    private ?string $source = null;

    private ?string $revision = null;

    /**
     * Прочитать документацию из существующего индекса ДО его пересоздания.
     *
     * Несовместимая версия схемы или повреждённый файл — не ошибка: значит,
     * переносить нечего, и сбор придётся повторить. Молчать об этом нельзя,
     * поэтому вызывающий получает признак через `count()`.
     */
    public function capture(string $indexPath): void
    {
        $this->pages = [];
        $this->crawledAt = null;
        $this->source = null;
        $this->revision = null;

        if (!is_file($indexPath)) {
            return;
        }

        try {
            $pdo = new PDO('sqlite:' . $indexPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);

            if (!$this->hasCompatibleDocColumns($pdo)) {
                // Версия всей схемы может измениться из-за таблиц кода,
                // не затрагивая doc_page. Переносим только после точной
                // проверки каждой нужной колонки, а не по предположению.
                return;
            }

            $columns = $this->columnNames($pdo, 'doc_page');
            $sourceSelect = in_array('source_type', $columns, true)
                ? 'source_type, source_ref, source_revision'
                : "'web' AS source_type, url AS source_ref, NULL AS source_revision";
            $stmt = $pdo->query(
                'SELECT url, section, title, content, fetched_at, etag, content_sha256,
                        version_mentions, links, ' . $sourceSelect . '
                 FROM doc_page'
            );

            if ($stmt === false) {
                return;
            }

            /** @var list<array<string, mixed>> $rows */
            $rows = $stmt->fetchAll();
            $this->pages = $rows;

            $at = $pdo->query('SELECT docs_crawled_at FROM snapshot WHERE id = 1');
            $value = $at === false ? null : $at->fetchColumn();
            $this->crawledAt = is_string($value) ? $value : null;

            $snapshotColumns = $this->columnNames($pdo, 'snapshot');
            if (in_array('docs_source', $snapshotColumns, true)) {
                $metadataStatement = $pdo->query(
                    'SELECT docs_source, docs_source_revision FROM snapshot WHERE id = 1'
                );
                $metadata = $metadataStatement === false
                    ? false
                    : $metadataStatement->fetch(PDO::FETCH_ASSOC);
                $this->source = is_array($metadata) && is_string($metadata['docs_source'] ?? null)
                    ? $metadata['docs_source']
                    : null;
                $this->revision = is_array($metadata) && is_string($metadata['docs_source_revision'] ?? null)
                    ? $metadata['docs_source_revision']
                    : null;
            }
        } catch (Throwable) {
            $this->pages = [];
            $this->crawledAt = null;
            $this->source = null;
            $this->revision = null;
        }
    }

    public function count(): int
    {
        return count($this->pages);
    }

    /** Восстановить документацию в свежесозданный индекс. */
    public function restore(PDO $pdo): void
    {
        if ($this->pages === []) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO doc_page
                (url, section, title, content, fetched_at, etag, content_sha256, version_mentions,
                 links, source_type, source_ref, source_revision)
             VALUES (:url, :section, :title, :content, :at, :etag, :sha, :versions,
                     :links, :source_type, :source_ref, :source_revision)'
        );

        foreach ($this->pages as $page) {
            $insert->execute([
                ':url' => $page['url'],
                ':section' => $page['section'],
                ':title' => $page['title'],
                ':content' => $page['content'],
                ':at' => $page['fetched_at'],
                ':etag' => $page['etag'],
                ':sha' => $page['content_sha256'],
                ':versions' => $page['version_mentions'],
                ':links' => $page['links'],
                ':source_type' => $page['source_type'],
                ':source_ref' => $page['source_ref'],
                ':source_revision' => $page['source_revision'],
            ]);
        }

        Schema::rebuildDocsIndex($pdo);
    }

    public function crawledAt(): ?string
    {
        return $this->crawledAt;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    public function revision(): ?string
    {
        return $this->revision;
    }

    private function hasCompatibleDocColumns(PDO $pdo): bool
    {
        $required = [
            'url', 'section', 'title', 'content', 'fetched_at', 'etag',
            'content_sha256', 'version_mentions', 'links',
        ];
        $present = $this->columnNames($pdo, 'doc_page');

        return array_diff($required, $present) === [];
    }

    /** @return list<string> */
    private function columnNames(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query('PRAGMA table_info(' . $table . ')');
        if ($stmt === false) {
            return [];
        }

        $present = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            if (isset($column['name']) && is_string($column['name'])) {
                $present[] = $column['name'];
            }
        }

        return $present;
    }
}
