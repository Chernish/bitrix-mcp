<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Integration;

use BitrixMcp\Indexer\Command\BuildCommand;
use BitrixMcp\Indexer\Command\Reporter;
use BitrixMcp\Indexer\ExitCode;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * База для интеграционных прогонов: строит индекс по мини-ядру из фикстур.
 *
 * Мини-ядро повторяет особенности настоящего: файл с коротким тегом,
 * класс с импортами и псевдонимом импорта, вызов class_alias, модуль
 * с версией и главный модуль с константами.
 */
abstract class IndexBuildTestCase extends TestCase
{
    protected string $indexPath;
    protected PDO $pdo;

    protected function coreRoot(): string
    {
        return realpath(__DIR__ . '/../fixtures/mini-core') ?: '';
    }

    protected function setUp(): void
    {
        $this->indexPath = sys_get_temp_dir() . '/bitrix-mcp-test-' . getmypid() . '.sqlite';

        $exit = $this->buildIndex($this->indexPath);
        self::assertSame(ExitCode::Success, $exit, 'Индексация мини-ядра не удалась');

        $this->pdo = new PDO('sqlite:' . $this->indexPath);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    protected function tearDown(): void
    {
        unset($this->pdo);

        foreach ([$this->indexPath, $this->indexPath . '-wal', $this->indexPath . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    protected function buildIndex(string $path): ExitCode
    {
        // Вывод в поток в памяти: тест не должен засорять stderr прогоном.
        $sink = fopen('php://memory', 'w+');
        self::assertIsResource($sink);

        return (new BuildCommand())->run(
            ['core' => $this->coreRoot(), 'index' => $path],
            new Reporter($sink),
        );
    }

    /** @return list<array<string, mixed>> */
    protected function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    protected function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }
}
