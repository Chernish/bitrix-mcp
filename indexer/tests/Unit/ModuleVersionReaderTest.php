<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Unit;

use BitrixMcp\Indexer\Core\IndexRoot;
use BitrixMcp\Indexer\Core\ModuleVersionReader;
use BitrixMcp\Indexer\Core\VersionSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ModuleVersionReader::class)]
final class ModuleVersionReaderTest extends TestCase
{
    private ModuleVersionReader $reader;
    private IndexRoot $root;

    protected function setUp(): void
    {
        $this->reader = new ModuleVersionReader();
        $this->root = IndexRoot::core(__DIR__ . '/../fixtures/fake-core');
    }

    public function testReadsOrdinaryModuleFromVersionArray(): void
    {
        $module = $this->reader->readModule($this->root, 'crm');

        self::assertSame('24.1300.0', $module->version);
        self::assertSame('2024-12-31 19:58:00', $module->versionDate);
        self::assertSame(VersionSource::ModuleFile, $module->source);
        self::assertTrue($module->isKnown());
    }

    public function testReadsMainModuleFromSmVersionConstant(): void
    {
        $module = $this->reader->readModule($this->root, 'main');

        self::assertSame('25.100.300', $module->version);
        self::assertSame('2025-03-21 14:47:03', $module->versionDate);
        self::assertSame(
            VersionSource::SmVersion,
            $module->source,
            'Источник обязан отличаться: main версионируется константой, а не массивом',
        );
    }

    /**
     * Самый опасный случай. Подстановка версии сборки вместо неустановленной
     * версии модуля превращает честное незнание в уверенную ложь
     * и прямо запрещена Принципом II конституции.
     */
    public function testUnknownVersionIsNotSubstitutedWithBuildVersion(): void
    {
        $module = $this->reader->readModule($this->root, 'noversion');

        self::assertNull($module->version);
        self::assertSame(VersionSource::Unknown, $module->source);
        self::assertFalse($module->isKnown());

        $build = $this->reader->readBuildVersion($this->root);
        self::assertNotNull($build);
        self::assertNotSame(
            $build->smVersion,
            $module->version,
            'Версия сборки не должна подменять неизвестную версию модуля',
        );

        self::assertSame('noversion@версия неизвестна', $module->label());
    }

    public function testReadsBuildVersion(): void
    {
        $build = $this->reader->readBuildVersion($this->root);

        self::assertNotNull($build);
        self::assertSame('25.100.300', $build->smVersion);
        self::assertSame('2025-03-21 14:47:03', $build->smVersionDate);
    }

    public function testReadAllReturnsEveryModuleDirectory(): void
    {
        $all = $this->reader->readAll($this->root);

        self::assertArrayHasKey('crm', $all);
        self::assertArrayHasKey('main', $all);
        self::assertArrayHasKey(
            'noversion',
            $all,
            'Модуль без файла версии обязан присутствовать в карте, а не исчезать из неё',
        );
    }

    public function testMissingModuleYieldsUnknownRatherThanThrowing(): void
    {
        $module = $this->reader->readModule($this->root, 'no-such-module');

        self::assertSame(VersionSource::Unknown, $module->source);
    }
}
