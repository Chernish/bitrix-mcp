<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Storage;

use BitrixMcp\Indexer\ExitCode;
use BitrixMcp\Indexer\Preflight\PreflightFailure;
use PDO;
use RuntimeException;

/**
 * @phpstan-type Metadata array{
 *   id: string,
 *   file: string,
 *   sm_version: string|null,
 *   sm_version_date: string|null,
 *   indexed_at: string,
 *   core_root: string|null,
 *   docs_crawled_at: string|null,
 *   docs_source: string|null,
 *   docs_source_revision: string|null,
 *   size_bytes: int,
 *   sha256: string
 * }
 * @phpstan-type Catalog array{version: int, snapshots: list<Metadata>}
 */
final class SnapshotStore
{
    private const CATALOG_VERSION = 1;

    private readonly string $directory;
    private readonly string $catalogPath;
    private readonly string $activePath;

    public function __construct(private readonly string $indexPath)
    {
        $this->directory = $indexPath . '.snapshots';
        $this->catalogPath = $this->directory . DIRECTORY_SEPARATOR . 'catalog.json';
        $this->activePath = $this->directory . DIRECTORY_SEPARATOR . 'active.json';
    }

    public function stage(): string
    {
        $this->ensureDirectory();
        $staging = $this->temporaryPath('staging');

        if (!is_file($this->indexPath)) {
            return $staging;
        }

        $pdo = Database::open($this->indexPath);
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $pdo->exec('VACUUM INTO ' . $this->quote($staging));

        return $staging;
    }

    /** @return Metadata */
    public function publish(string $staging): array
    {
        $metadata = $this->archive($staging);
        $this->promote($metadata);
        $this->writeActive($metadata);

        return $metadata;
    }

    /** @return Metadata */
    public function archiveCurrent(): array
    {
        $activeId = $this->activeId();
        if ($activeId !== null) {
            return $this->metadata($activeId);
        }

        return $this->publish($this->stage());
    }

    /** @return Metadata */
    public function activate(string $id): array
    {
        $metadata = $this->metadata($id);
        $this->promote($metadata);
        $this->writeActive($metadata);

        return $metadata;
    }

    public function delete(string $id): void
    {
        $metadata = $this->metadata($id);
        if ($this->activeId() === $id) {
            throw new PreflightFailure(
                "ОШИБКА: нельзя удалить активный снимок {$id}. Сначала активируйте другой.",
                ExitCode::ConfigError,
            );
        }

        $catalog = $this->readCatalog();
        $catalog['snapshots'] = array_values(array_filter(
            $catalog['snapshots'],
            static fn(array $item): bool => $item['id'] !== $id,
        ));
        $this->writeCatalog($catalog);

        $path = $this->path($metadata);
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException("не удалось удалить снимок {$path}");
        }
    }

    /** @return Metadata */
    public function metadata(string $id): array
    {
        self::assertId($id);

        foreach ($this->readCatalog()['snapshots'] as $metadata) {
            if ($metadata['id'] === $id) {
                return $metadata;
            }
        }

        throw new PreflightFailure("ОШИБКА: снимок не найден: {$id}", ExitCode::ConfigError);
    }

    /** @return array{active_id: string|null, total_bytes: int, snapshots: list<Metadata>} */
    public function list(): array
    {
        $snapshots = $this->readCatalog()['snapshots'];
        usort($snapshots, static function (array $a, array $b): int {
            $byDate = strcmp($b['indexed_at'], $a['indexed_at']);

            return $byDate !== 0 ? $byDate : strcmp($a['id'], $b['id']);
        });

        return [
            'active_id' => $this->activeId(),
            'total_bytes' => array_sum(array_column($snapshots, 'size_bytes')),
            'snapshots' => $snapshots,
        ];
    }

    public function activeId(): ?string
    {
        if (!is_file($this->indexPath)) {
            return null;
        }

        $hash = hash_file('sha256', $this->indexPath);
        if (!is_string($hash)) {
            throw new RuntimeException("не удалось хешировать активный индекс {$this->indexPath}");
        }

        $snapshots = $this->readCatalog()['snapshots'];
        $activeId = $this->readActiveId();
        if ($activeId !== null) {
            foreach ($snapshots as $metadata) {
                if ($metadata['id'] === $activeId && hash_equals($metadata['sha256'], $hash)) {
                    return $activeId;
                }
            }
        }

        foreach (array_reverse($snapshots) as $metadata) {
            if (hash_equals($metadata['sha256'], $hash)) {
                return $metadata['id'];
            }
        }

        return null;
    }

    public function discard(string $staging): void
    {
        foreach ([$staging, $staging . '-wal', $staging . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** @param Metadata $metadata */
    private function promote(array $metadata): void
    {
        $archive = $this->path($metadata);
        $actualHash = hash_file('sha256', $archive);
        if (!is_string($actualHash) || !hash_equals($metadata['sha256'], $actualHash)) {
            throw new PreflightFailure(
                "ОШИБКА: содержимое снимка {$metadata['id']} не совпадает с каталогом.",
                ExitCode::ConfigError,
            );
        }

        $temporary = $this->temporaryPath('activate');
        if (!copy($archive, $temporary)) {
            throw new RuntimeException("не удалось скопировать снимок {$metadata['id']}");
        }

        $suffix = $this->suffix();
        $sidecars = [];
        foreach ([$this->indexPath . '-wal', $this->indexPath . '-shm'] as $path) {
            if (!is_file($path)) {
                continue;
            }

            $backup = $path . '.' . $suffix;
            if (!rename($path, $backup)) {
                $this->discard($temporary);
                throw new RuntimeException("не удалось отключить sidecar {$path}");
            }
            $sidecars[] = $backup;
        }

        if (!rename($temporary, $this->indexPath)) {
            foreach ($sidecars as $backup) {
                rename($backup, preg_replace('/\.' . $suffix . '$/', '', $backup) ?: $backup);
            }
            $this->discard($temporary);
            throw new RuntimeException("не удалось активировать снимок {$metadata['id']}");
        }

        foreach ($sidecars as $backup) {
            unlink($backup);
        }
    }

    /** @return Metadata */
    private function archive(string $staging): array
    {
        if (!is_file($staging)) {
            throw new RuntimeException("временный индекс не найден: {$staging}");
        }

        $pdo = Database::open($staging);
        SchemaVersionGuard::enforce($pdo, $staging);
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $integrity = $pdo->query('PRAGMA integrity_check');
        $result = $integrity === false ? false : $integrity->fetchColumn();
        if ($result !== 'ok') {
            throw new RuntimeException("временный индекс не прошёл integrity_check: {$staging}");
        }

        $snapshotStatement = $pdo->query('SELECT * FROM snapshot WHERE id = 1');
        $snapshot = $snapshotStatement === false ? false : $snapshotStatement->fetch();
        if (!is_array($snapshot)) {
            throw new RuntimeException("во временном индексе нет снимка: {$staging}");
        }
        $rootStatement = $pdo->query('SELECT path FROM index_root WHERE is_core = 1 LIMIT 1');
        $root = $rootStatement === false ? false : $rootStatement->fetchColumn();
        unset($pdo);

        foreach ([$staging . '-wal', $staging . '-shm'] as $sidecar) {
            if (is_file($sidecar)) {
                unlink($sidecar);
            }
        }

        $hash = hash_file('sha256', $staging);
        $size = filesize($staging);
        if (!is_string($hash) || $size === false) {
            throw new RuntimeException("не удалось прочитать временный индекс {$staging}");
        }

        $smVersion = is_string($snapshot['sm_version'] ?? null) ? $snapshot['sm_version'] : null;
        $id = $this->newId($smVersion, $hash);
        $metadata = [
            'id' => $id,
            'file' => $id . '.sqlite',
            'sm_version' => $smVersion,
            'sm_version_date' => self::nullableString($snapshot['sm_version_date'] ?? null),
            'indexed_at' => (string)$snapshot['indexed_at'],
            'core_root' => is_string($root) ? $root : null,
            'docs_crawled_at' => self::nullableString($snapshot['docs_crawled_at'] ?? null),
            'docs_source' => self::nullableString($snapshot['docs_source'] ?? null),
            'docs_source_revision' => self::nullableString($snapshot['docs_source_revision'] ?? null),
            'size_bytes' => $size,
            'sha256' => $hash,
        ];

        $archive = $this->path($metadata);
        if (!rename($staging, $archive)) {
            throw new RuntimeException("не удалось переместить снимок в {$archive}");
        }
        chmod($archive, 0o444);

        $catalog = $this->readCatalog();
        $catalog['snapshots'][] = $metadata;
        $this->writeCatalog($catalog);

        return $metadata;
    }

    private function newId(?string $smVersion, string $hash): string
    {
        $version = preg_replace('/[^A-Za-z0-9._-]+/', '-', $smVersion ?? 'unknown') ?: 'unknown';

        return gmdate('Ymd\THis\Z') . '-' . trim($version, '-.') . '-'
            . substr($hash, 0, 10) . '-' . bin2hex(random_bytes(3));
    }

    /** @return Catalog */
    private function readCatalog(): array
    {
        if (!is_file($this->catalogPath)) {
            return ['version' => self::CATALOG_VERSION, 'snapshots' => []];
        }

        $raw = file_get_contents($this->catalogPath);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (
            !is_array($data) || ($data['version'] ?? null) !== self::CATALOG_VERSION
            || !isset($data['snapshots']) || !is_array($data['snapshots'])
        ) {
            throw new RuntimeException("повреждён каталог снимков: {$this->catalogPath}");
        }

        /** @var list<Metadata> $snapshots */
        $snapshots = [];
        foreach ($data['snapshots'] as $row) {
            if (!is_array($row)) {
                throw new RuntimeException("повреждён каталог снимков: {$this->catalogPath}");
            }
            $snapshots[] = $this->normalizeMetadata($row);
        }

        return ['version' => self::CATALOG_VERSION, 'snapshots' => $snapshots];
    }

    /**
     * @param array<string, mixed> $row
     * @return Metadata
     */
    private function normalizeMetadata(array $row): array
    {
        $strings = ['id', 'file', 'indexed_at', 'sha256'];
        foreach ($strings as $key) {
            if (!is_string($row[$key] ?? null)) {
                throw new RuntimeException("повреждён каталог снимков: {$this->catalogPath}");
            }
        }
        self::assertId($row['id']);
        if (
            $row['file'] !== $row['id'] . '.sqlite' || !is_int($row['size_bytes'] ?? null)
            || !preg_match('/\A[0-9a-f]{64}\z/', $row['sha256'])
        ) {
            throw new RuntimeException("повреждён каталог снимков: {$this->catalogPath}");
        }

        $nullable = [
            'sm_version', 'sm_version_date', 'core_root', 'docs_crawled_at',
            'docs_source', 'docs_source_revision',
        ];
        foreach ($nullable as $key) {
            if (!is_string($row[$key] ?? null) && ($row[$key] ?? null) !== null) {
                throw new RuntimeException("повреждён каталог снимков: {$this->catalogPath}");
            }
        }

        /** @var Metadata $metadata */
        $metadata = [
            'id' => $row['id'],
            'file' => $row['file'],
            'sm_version' => $row['sm_version'] ?? null,
            'sm_version_date' => $row['sm_version_date'] ?? null,
            'indexed_at' => $row['indexed_at'],
            'core_root' => $row['core_root'] ?? null,
            'docs_crawled_at' => $row['docs_crawled_at'] ?? null,
            'docs_source' => $row['docs_source'] ?? null,
            'docs_source_revision' => $row['docs_source_revision'] ?? null,
            'size_bytes' => $row['size_bytes'],
            'sha256' => $row['sha256'],
        ];

        return $metadata;
    }

    private function readActiveId(): ?string
    {
        if (!is_file($this->activePath)) {
            return null;
        }

        $raw = file_get_contents($this->activePath);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || !is_string($data['id'] ?? null)) {
            throw new RuntimeException("повреждён указатель активного снимка: {$this->activePath}");
        }
        self::assertId($data['id']);

        return $data['id'];
    }

    /** @param Metadata $metadata */
    private function writeActive(array $metadata): void
    {
        $temporary = $this->activePath . '.' . $this->suffix() . '.tmp';
        $json = json_encode(['id' => $metadata['id']], JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json . PHP_EOL) === false || !rename($temporary, $this->activePath)) {
            throw new RuntimeException("не удалось записать активный снимок: {$this->activePath}");
        }
    }

    /** @param Catalog $catalog */
    private function writeCatalog(array $catalog): void
    {
        $this->ensureDirectory();
        $temporary = $this->catalogPath . '.' . $this->suffix() . '.tmp';
        $json = json_encode(
            $catalog,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        if (file_put_contents($temporary, $json . PHP_EOL) === false || !rename($temporary, $this->catalogPath)) {
            throw new RuntimeException("не удалось записать каталог снимков: {$this->catalogPath}");
        }
    }

    /** @param Metadata $metadata */
    private function path(array $metadata): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $metadata['file'];
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw new RuntimeException("не удалось создать каталог снимков: {$this->directory}");
        }
    }

    private function temporaryPath(string $prefix): string
    {
        return $this->indexPath . '.' . $prefix . '-' . $this->suffix() . '.sqlite';
    }

    private function suffix(): string
    {
        return getmypid() . '-' . bin2hex(random_bytes(4));
    }

    private function quote(string $path): string
    {
        return "'" . str_replace("'", "''", $path) . "'";
    }

    private static function assertId(string $id): void
    {
        if (!preg_match('/\A[A-Za-z0-9._-]+\z/', $id)) {
            throw new PreflightFailure("ОШИБКА: некорректный ID снимка: {$id}", ExitCode::ConfigError);
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
