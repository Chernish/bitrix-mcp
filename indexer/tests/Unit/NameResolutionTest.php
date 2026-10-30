<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Unit;

use BitrixMcp\Indexer\Extract\ApiStyle;
use BitrixMcp\Indexer\Extract\FileExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Резолв имён — область, где текстовая эвристика ошибается предсказуемо,
 * а цена ошибки тихая: неверно разрешённый родитель не ломает индексацию,
 * он просто делает связь неверной. Поэтому тест обязателен (раздел
 * конституции «Качество данных и проверка»).
 */
final class NameResolutionTest extends TestCase
{
    private FileExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FileExtractor();
    }

    public function testResolvesNamespaceAndImportAlias(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App\Domain;

        use Vendor\Lib\BaseEntity as Base;
        use Vendor\Lib\Contracts\Speaks;

        class Widget extends Base implements Speaks
        {
        }
        PHP;

        [$extraction] = $this->extractor->extract($code);
        self::assertNotNull($extraction);
        self::assertCount(1, $extraction->symbols);

        $symbol = $extraction->symbols[0];

        self::assertSame('\App\Domain\Widget', $symbol->fqn);
        self::assertSame('Widget', $symbol->shortName);
        self::assertSame(
            '\Vendor\Lib\BaseEntity',
            $symbol->parentFqn,
            'Псевдоним импорта не разрешён в полное имя — текстовая эвристика вернула бы «Base»',
        );
        self::assertSame(['\Vendor\Lib\Contracts\Speaks'], $symbol->interfaces);
    }

    public function testResolvesTraitImports(): void
    {
        $code = <<<'PHP'
        <?php
        namespace App\Domain;

        use Vendor\Lib\Concerns\LogsActivity as Logs;
        use Vendor\Lib\Concerns\TracksChanges;

        class Widget
        {
            use Logs;
            use TracksChanges;
        }
        PHP;

        [$extraction] = $this->extractor->extract($code);
        self::assertNotNull($extraction);
        self::assertSame(
            ['\\Vendor\\Lib\\Concerns\\LogsActivity', '\\Vendor\\Lib\\Concerns\\TracksChanges'],
            $extraction->symbols[0]->traits,
        );
    }

    public function testResolvesRelativeNamesAgainstCurrentNamespace(): void
    {
        $code = <<<'PHP'
        <?php
        namespace Bitrix\Main\Entity;

        class Child extends Base\Parent_
        {
        }
        PHP;

        [$extraction] = $this->extractor->extract($code);
        self::assertNotNull($extraction);

        self::assertSame(
            '\Bitrix\Main\Entity\Base\Parent_',
            $extraction->symbols[0]->parentFqn,
        );
    }

    public function testDetectsApiStyleAndDeprecation(): void
    {
        $code = <<<'PHP'
        <?php
        /**
         * @deprecated 24.0.0 используйте \Bitrix\Iblock\ElementTable
         */
        class CIBlockElement
        {
        }
        PHP;

        [$extraction] = $this->extractor->extract($code);
        self::assertNotNull($extraction);

        $symbol = $extraction->symbols[0];

        self::assertTrue($symbol->isDeprecated, 'Факт из @deprecated не прочитан');
        self::assertStringContainsString('ElementTable', (string)$symbol->deprecatedNote);
        self::assertSame(
            ApiStyle::Legacy,
            $symbol->apiStyle,
            'Эвристика поколения API не сработала на характерной форме имени',
        );
    }

    public function testD7NamespaceYieldsD7Style(): void
    {
        $code = "<?php\nnamespace Bitrix\\Iblock;\nclass IblockTable {}\n";

        [$extraction] = $this->extractor->extract($code);
        self::assertNotNull($extraction);
        self::assertSame(ApiStyle::D7, $extraction->symbols[0]->apiStyle);
    }

    public function testAnonymousClassesAreSkipped(): void
    {
        $code = "<?php\n\$x = new class { public function a() {} };\n";

        [$extraction] = $this->extractor->extract($code);
        self::assertNotNull($extraction);
        self::assertSame([], $extraction->symbols, 'Анонимный класс не адресуем по имени и агенту бесполезен');
    }

    public function testParseErrorIsReturnedNotThrown(): void
    {
        [$extraction, $error, $line] = $this->extractor->extract("<?php\nclass Broken {\n");

        self::assertNull($extraction);
        self::assertIsString($error, 'Ошибка обязана возвращаться, а не всплывать исключением');
        self::assertNotNull($line);
    }
}
