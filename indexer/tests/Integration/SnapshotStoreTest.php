<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Integration;

use BitrixMcp\Indexer\Command\BuildCommand;
use BitrixMcp\Indexer\Command\Reporter;
use BitrixMcp\Indexer\Command\UpdateCommand;
use BitrixMcp\Indexer\Command\VersionedCommand;
use BitrixMcp\Indexer\ExitCode;
use BitrixMcp\Indexer\Preflight\PreflightFailure;
use BitrixMcp\Indexer\Storage\SnapshotStore;
use FilesystemIterator;
use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SnapshotStoreTest extends TestCase
{
    private string $coreDir;
    private string $indexPath;

    protected function setUp(): void
    {
        $this->coreDir = sys_get_temp_dir() . '/bitrix-mcp-snapshot-core-' . getmypid() . '-' . uniqid();
        $this->indexPath = $this->coreDir . '.sqlite';
        $this->copyTree(__DIR__ . '/../fixtures/mini-core', $this->coreDir);

        self::assertSame(ExitCode::Success, $this->build());
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->coreDir);
        $this->removeTree($this->indexPath . '.snapshots');

        foreach ([$this->indexPath, $this->indexPath . '-wal', $this->indexPath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testPreviousVersionSurvivesUpdateWithoutMixingSymbols(): void
    {
        $store = new SnapshotStore($this->indexPath);
        $first = $store->list()['snapshots'][0];
        $this->changeCore();

        self::assertSame(ExitCode::Success, $this->update());

        $second = $store->metadata($store->activeId() ?? '');
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame('100.200.0', $second['sm_version']);
        self::assertCount(2, $store->list()['snapshots']);

        $firstPdo = $this->pdo($this->archivePath($first));
        self::assertSame('99.100.200', $this->scalar($firstPdo, 'SELECT sm_version FROM snapshot WHERE id = 1'));
        self::assertSame(0, $this->scalar($firstPdo, "SELECT COUNT(*) FROM symbol WHERE short_name = 'CVersionedB'"));

        $secondPdo = $this->pdo($this->archivePath($second));
        self::assertSame('100.200.0', $this->scalar($secondPdo, 'SELECT sm_version FROM snapshot WHERE id = 1'));
        self::assertSame(1, $this->scalar($secondPdo, "SELECT COUNT(*) FROM symbol WHERE short_name = 'CVersionedB'"));
    }

    public function testFailedBuildDoesNotChangeActiveSnapshot(): void
    {
        $store = new SnapshotStore($this->indexPath);
        $beforeHash = hash_file('sha256', $this->indexPath);
        $before = $store->list();
        file_put_contents($this->coreDir . '/modules/testmod/lib/broken.php', "<?php\nclass Broken {\n");

        self::assertSame(ExitCode::ParseErrorThreshold, $this->build());
        self::assertSame($beforeHash, hash_file('sha256', $this->indexPath));
        self::assertSame($before, $store->list());
        self::assertSame([], glob($this->indexPath . '.staging-*.sqlite') ?: []);
    }

    public function testRepeatedVersionGetsNewIdAndActiveCannotBeDeleted(): void
    {
        $store = new SnapshotStore($this->indexPath);
        $firstId = $store->activeId();
        self::assertNotNull($firstId);

        self::assertSame(ExitCode::Success, $this->build());
        $secondId = $store->activeId();
        self::assertNotNull($secondId);
        self::assertNotSame($firstId, $secondId);
        self::assertCount(2, $store->list()['snapshots']);

        $store->activate($firstId);
        self::assertSame($firstId, $store->activeId());

        try {
            $store->delete($firstId);
            self::fail('Активный снимок удалён');
        } catch (PreflightFailure) {
        }

        $store->delete($secondId);
        self::assertCount(1, $store->list()['snapshots']);
        self::assertSame($firstId, $store->activeId());
    }

    public function testDocsRevisionStaysWithItsSnapshot(): void
    {
        $store = new SnapshotStore($this->indexPath);
        $this->setDocsRevision('docs-A');
        $first = $store->archiveCurrent();
        $this->setDocsRevision('docs-B');
        $second = $store->archiveCurrent();

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame('docs-A', $first['docs_source_revision']);
        self::assertSame('docs-B', $second['docs_source_revision']);

        $store->activate($first['id']);
        $active = $this->pdo($this->indexPath);
        self::assertSame('docs-A', $this->scalar($active, 'SELECT docs_source_revision FROM snapshot WHERE id = 1'));

        $archive = $this->pdo($this->archivePath($second));
        self::assertSame('docs-B', $this->scalar($archive, 'SELECT docs_source_revision FROM snapshot WHERE id = 1'));
    }

    private function changeCore(): void
    {
        file_put_contents(
            $this->coreDir . '/modules/main/classes/general/version.php',
            "<?php\ndefine('SM_VERSION', '100.200.0');\ndefine('SM_VERSION_DATE', '2026-02-02 00:00:00');\n",
        );
        file_put_contents(
            $this->coreDir . '/modules/testmod/lib/versioned-b.php',
            "<?php\nclass CVersionedB {}\n",
        );
    }

    private function setDocsRevision(string $revision): void
    {
        $pdo = $this->pdo($this->indexPath);
        $statement = $pdo->prepare(
            'UPDATE snapshot SET docs_crawled_at = ?, docs_source = ?, docs_source_revision = ? WHERE id = 1',
        );
        $statement->execute(['2026-10-28T00:00:00+00:00', 'git', $revision]);
        unset($statement, $pdo);
    }

    private function build(): ExitCode
    {
        return (new VersionedCommand(new BuildCommand()))->run(
            ['core' => $this->coreDir, 'index' => $this->indexPath],
            new Reporter($this->sink()),
        );
    }

    private function update(): ExitCode
    {
        return (new VersionedCommand(new UpdateCommand()))->run(
            ['core' => $this->coreDir, 'index' => $this->indexPath],
            new Reporter($this->sink()),
        );
    }

    /** @return resource */
    private function sink()
    {
        $sink = fopen('php://memory', 'w+');
        self::assertIsResource($sink);

        return $sink;
    }

    /** @param array{file: string} $metadata */
    private function archivePath(array $metadata): string
    {
        return $this->indexPath . '.snapshots/' . $metadata['file'];
    }

    private function pdo(string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    private function scalar(PDO $pdo, string $sql): mixed
    {
        return $pdo->query($sql)->fetchColumn();
    }

    private function copyTree(string $source, string $target): void
    {
        mkdir($target, 0o775, true);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $destination = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                mkdir($destination, 0o775, true);
            } else {
                copy($item->getPathname(), $destination);
            }
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
