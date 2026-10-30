<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Integration;

use BitrixMcp\Indexer\Command\BuildCommand;
use BitrixMcp\Indexer\Command\Reporter;
use BitrixMcp\Indexer\Command\UpdateCommand;
use BitrixMcp\Indexer\ExitCode;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Инкрементальная переиндексация.
 *
 * Работает на ИЗМЕНЯЕМОЙ копии мини-ядра: тест обязан модифицировать и удалять
 * файлы, а трогать фикстуры в репозитории для этого нельзя.
 */
final class IncrementalUpdateTest extends TestCase
{
    private string $coreDir;
    private string $indexPath;

    protected function setUp(): void
    {
        $this->coreDir = sys_get_temp_dir() . '/bitrix-mcp-core-' . getmypid() . '-' . uniqid();
        $this->indexPath = $this->coreDir . '.sqlite';

        $this->copyTree(__DIR__ . '/../fixtures/mini-core', $this->coreDir);

        self::assertSame(ExitCode::Success, $this->build());
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->coreDir);

        foreach ([$this->indexPath, $this->indexPath . '-wal', $this->indexPath . '-shm'] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }

    public function testUnchangedCoreRebuildsNothing(): void
    {
        $before = $this->counts();

        self::assertSame(ExitCode::Success, $this->update());

        self::assertSame($before, $this->counts(), 'Обновление без изменений изменило содержимое индекса');
    }

    public function testChangedFileIsReindexed(): void
    {
        self::assertNotNull($this->findSymbol('CTestModLegacy'));
        self::assertNull($this->findSymbol('CBrandNewClass'));

        $path = $this->coreDir . '/modules/testmod/classes/general/legacy.php';
        $code = (string)file_get_contents($path);
        file_put_contents($path, $code . "\nclass CBrandNewClass { public function hi() {} }\n");
        touch($path, time() + 10);

        self::assertSame(ExitCode::Success, $this->update());

        self::assertNotNull(
            $this->findSymbol('CBrandNewClass'),
            'Новый класс из изменённого файла не попал в индекс',
        );
        self::assertNotNull(
            $this->findSymbol('CTestModLegacy'),
            'Прежний класс того же файла пропал при перестройке',
        );
    }

    public function testCodeSearchIndexFollowsChangedFiles(): void
    {
        $path = $this->coreDir . '/modules/testmod/lib/searchable.php';
        file_put_contents(
            $path,
            "<?php\n/** Uniqueoldtoken describes an API that is present in the original core. */\n"
            . "class SearchableOldApi {}\n",
        );

        self::assertSame(ExitCode::Success, $this->build());
        self::assertSame(
            '\\SearchableOldApi',
            $this->scalar('SELECT fqn FROM fts_code WHERE fts_code MATCH ?', ['Uniqueoldtoken']),
        );

        file_put_contents(
            $path,
            "<?php\n/** Uniquefreshtoken describes an API that is present in the updated core. */\n"
            . "class SearchableFreshApi {}\n",
        );
        touch($path, time() + 10);

        self::assertSame(ExitCode::Success, $this->update());
        self::assertSame(
            0,
            (int)$this->scalar('SELECT COUNT(*) FROM fts_code WHERE fts_code MATCH ?', ['Uniqueoldtoken']),
            'Удалённый класс остался в полнотекстовом поиске',
        );
        self::assertSame(
            '\\SearchableFreshApi',
            $this->scalar('SELECT fqn FROM fts_code WHERE fts_code MATCH ?', ['Uniquefreshtoken']),
            'Новый класс не появился в полнотекстовом поиске',
        );
    }

    public function testDeletedFileRemovesItsRecords(): void
    {
        self::assertNotNull($this->findSymbol('Thing'));
        self::assertGreaterThan(0, $this->scalar('SELECT COUNT(*) FROM symbol_alias'));

        unlink($this->coreDir . '/modules/testmod/lib/thing.php');

        self::assertSame(ExitCode::Success, $this->update());

        self::assertNull($this->findSymbol('Thing'), 'Символ удалённого файла остался в индексе');
        self::assertSame(
            0,
            (int)$this->scalar('SELECT COUNT(*) FROM symbol_alias'),
            'Псевдоним удалённого файла остался — каскад по file_id не сработал',
        );
    }

    public function testTouchWithoutContentChangeDoesNotRebuild(): void
    {
        $symbolId = $this->scalar('SELECT id FROM symbol WHERE short_name = ?', ['Thing']);
        self::assertNotFalse($symbolId);

        // Распаковка архива обновления меняет mtime, не трогая содержимое.
        touch($this->coreDir . '/modules/testmod/lib/thing.php', time() + 20);

        self::assertSame(ExitCode::Success, $this->update());

        self::assertSame(
            $symbolId,
            $this->scalar('SELECT id FROM symbol WHERE short_name = ?', ['Thing']),
            'Файл перестроен, хотя содержимое не менялось — SHA-256 не используется как арбитр',
        );
    }

    public function testModuleVersionsArePickedUpOnUpdate(): void
    {
        self::assertSame('42.7.0', $this->scalar('SELECT version FROM module WHERE code = ?', ['testmod']));

        file_put_contents(
            $this->coreDir . '/modules/testmod/install/version.php',
            "<?php\n\$arModuleVersion = [\"VERSION\" => \"43.0.0\", \"VERSION_DATE\" => \"2026-06-06 00:00:00\"];\n",
        );

        self::assertSame(ExitCode::Success, $this->update());

        self::assertSame(
            '43.0.0',
            $this->scalar('SELECT version FROM module WHERE code = ?', ['testmod']),
            'Карта версий обязана перечитываться целиком при каждом обновлении (Принцип II)',
        );
    }

    public function testBuildVersionChangeIsReflected(): void
    {
        file_put_contents(
            $this->coreDir . '/modules/main/classes/general/version.php',
            "<?php\ndefine(\"SM_VERSION\", \"99.200.0\");\ndefine(\"SM_VERSION_DATE\", \"2026-07-07 00:00:00\");\n",
        );

        self::assertSame(ExitCode::Success, $this->update());

        self::assertSame('99.200.0', $this->scalar('SELECT sm_version FROM snapshot WHERE id = 1'));
        self::assertSame('99.200.0', $this->scalar('SELECT version FROM module WHERE code = ?', ['main']));
    }

    public function testMovedCoreReplacesOldRootAndModules(): void
    {
        $moved = $this->coreDir . '-moved';
        self::assertTrue(rename($this->coreDir, $moved));
        $this->coreDir = $moved;

        file_put_contents(
            $this->coreDir . '/modules/testmod/install/version.php',
            "<?php\n\$arModuleVersion = [\"VERSION\" => \"99.0.0\"];\n",
        );

        self::assertSame(ExitCode::Success, $this->update());
        self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM index_root'));
        self::assertSame(realpath($this->coreDir), $this->scalar('SELECT path FROM index_root'));
        self::assertSame(1, (int)$this->scalar('SELECT COUNT(*) FROM module WHERE code = ?', ['testmod']));
        self::assertSame('99.0.0', $this->scalar('SELECT version FROM module WHERE code = ?', ['testmod']));
        self::assertGreaterThan(
            0,
            (int)$this->scalar(
                'SELECT COUNT(*) FROM file f JOIN module m ON m.id = f.module_id WHERE m.code = ?',
                ['testmod'],
            ),
        );
    }

    public function testRemovedModuleIsDeletedWithItsFiles(): void
    {
        $this->removeTree($this->coreDir . '/modules/testmod');

        self::assertSame(ExitCode::Success, $this->update());
        self::assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM module WHERE code = ?', ['testmod']));
        self::assertSame(0, (int)$this->scalar('SELECT COUNT(*) FROM file WHERE module_id NOT IN (SELECT id FROM module)'));
    }

    public function testDependenciesFollowTheirSourceFiles(): void
    {
        $first = $this->coreDir . '/modules/testmod/lib/dependency-a.php';
        $second = $this->coreDir . '/modules/testmod/lib/dependency-b.php';
        $include = "\\Bitrix\\Main\\Loader::includeModule('sale');\n";
        file_put_contents($first, "<?php\n" . $include . $include);
        file_put_contents($second, "<?php\n" . $include);

        self::assertSame(ExitCode::Success, $this->update());
        self::assertSame(
            2,
            (int)$this->scalar(
                'SELECT SUM(occurrences) FROM module_dependency d
                 JOIN module m ON m.id = d.module_id WHERE m.code = ? AND d.depends_on_code = ?',
                ['testmod', 'sale'],
            ),
        );

        unlink($first);
        self::assertSame(ExitCode::Success, $this->update());
        self::assertSame(
            1,
            (int)$this->scalar(
                'SELECT SUM(occurrences) FROM module_dependency d
                 JOIN module m ON m.id = d.module_id WHERE m.code = ? AND d.depends_on_code = ?',
                ['testmod', 'sale'],
            ),
        );

        unlink($second);
        self::assertSame(ExitCode::Success, $this->update());
        self::assertSame(
            0,
            (int)$this->scalar(
                'SELECT COUNT(*) FROM module_dependency d
                 JOIN module m ON m.id = d.module_id WHERE m.code = ? AND d.depends_on_code = ?',
                ['testmod', 'sale'],
            ),
        );
    }

    /**
     * Регрессия: инкрементальный прогон не имеет права обнулить счётчик
     * ошибок разбора.
     *
     * Накопитель ошибок в памяти при обновлении без изменений пуст, потому что
     * ничего не переразбиралось. Записав его значение в снимок, команда
     * сообщала бы «ошибок нет» при живом непарсибельном файле в индексе —
     * то самое проглатывание, которое запрещает Принцип V.
     */
    public function testUpdateDoesNotZeroParseErrorCount(): void
    {
        // Кладём в ядро заведомо непарсибельный файл и строим индекс заново.
        $broken = $this->coreDir . '/modules/testmod/lib/broken.php';
        file_put_contents($broken, "<?php\nclass Unfinished {\n");

        // Сборка вернёт код 3: один битый файл из горстки — это заметно больше
        // порога SC-009 в 1%. Так и задумано, и здесь это не мешает: проверяется
        // счётчик, а не порог. Индекс при этом построен полностью.
        self::assertSame(ExitCode::ParseErrorThreshold, $this->build());

        $rows = (int)$this->scalar('SELECT COUNT(*) FROM parse_error');
        $counter = (int)$this->scalar('SELECT parse_error_count FROM snapshot WHERE id = 1');

        self::assertSame(1, $rows, 'Ошибка разбора не записана в таблицу');
        self::assertSame(1, $counter, 'Счётчик в снимке разошёлся с таблицей сразу после сборки');

        // Обновление без изменений перечитывает сохранённые ошибки и тоже
        // отклоняет индекс выше порога, хотя переразбирать файлы не потребовалось.
        self::assertSame(ExitCode::ParseErrorThreshold, $this->update());

        self::assertSame(
            1,
            (int)$this->scalar('SELECT COUNT(*) FROM parse_error'),
            'Запись об ошибке исчезла при обновлении',
        );
        self::assertSame(
            1,
            (int)$this->scalar('SELECT parse_error_count FROM snapshot WHERE id = 1'),
            'Счётчик обнулён при обновлении, хотя непарсибельный файл на месте',
        );
    }

    // ── вспомогательное ──────────────────────────────────────────────────

    private function build(): ExitCode
    {
        return (new BuildCommand())->run(
            ['core' => $this->coreDir, 'index' => $this->indexPath],
            new Reporter($this->sink()),
        );
    }

    private function update(): ExitCode
    {
        return (new UpdateCommand())->run(
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

    private function pdo(): PDO
    {
        $pdo = new PDO('sqlite:' . $this->indexPath);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        return $pdo;
    }

    private function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    private function findSymbol(string $shortName): ?string
    {
        $value = $this->scalar('SELECT fqn FROM symbol WHERE short_name = ?', [$shortName]);

        return $value === false ? null : (string)$value;
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $result = [];

        foreach (
            [
                'file', 'symbol', 'method', 'global_function', 'class_constant',
                'usage_reference', 'symbol_alias', 'event', 'orm_entity',
            ] as $table
        ) {
            $result[$table] = (int)$this->scalar("SELECT COUNT(*) FROM {$table}");
        }

        return $result;
    }

    private function copyTree(string $from, string $to): void
    {
        mkdir($to, 0o777, true);

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $target = $to . DIRECTORY_SEPARATOR . substr($item->getPathname(), strlen($from) + 1);

            if ($item->isDir()) {
                mkdir($target, 0o777, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
