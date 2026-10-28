<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Unit;

use BitrixMcp\Indexer\Extract\Component\ComponentExtractor;
use BitrixMcp\Indexer\Extract\FileExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Извлечение параметров компонентов.
 *
 * Главное, что здесь проверяется, — что файл НЕ выполняется и что
 * вычисляемые значения помечены, а не превращены в отсутствующие.
 * Это цена Принципа VI, и она обязана быть видимой (R-004).
 */
final class ComponentParametersTest extends TestCase
{
    private FileExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FileExtractor();
    }

    private const PATH = 'modules/main/install/components/bitrix/main.feedback/.parameters.php';

    public function testLiteralValuesAreExtracted(): void
    {
        [$extraction] = $this->extractor->extract(<<<'PHP'
        <?php
        $arComponentParameters = array(
            "PARAMETERS" => array(
                "USE_CAPTCHA" => Array(
                    "NAME" => GetMessage("MFP_CAPTCHA"),
                    "TYPE" => "CHECKBOX",
                    "DEFAULT" => "Y",
                    "PARENT" => "BASE",
                ),
                "REQUIRED_FIELDS" => Array(
                    "TYPE" => "LIST",
                    "MULTIPLE" => "Y",
                    "VALUES" => Array("NONE" => "все", "NAME" => "имя"),
                    "DEFAULT" => "",
                    "PARENT" => "BASE",
                ),
            ),
        );
        PHP, self::PATH);

        self::assertNotNull($extraction);
        $component = $extraction->component;
        self::assertNotNull($component);

        self::assertSame('bitrix', $component->namespace);
        self::assertSame('main.feedback', $component->name);
        self::assertSame('bitrix:main.feedback', $component->fullName);
        self::assertTrue($component->paramsResolved);
        self::assertCount(2, $component->params);

        $captcha = $component->params[0];
        self::assertSame('USE_CAPTCHA', $captcha->code);
        self::assertSame('CHECKBOX', $captcha->type);
        self::assertSame('Y', $captcha->defaultRepr);
        self::assertTrue($captcha->isDefaultResolved);
        self::assertSame('BASE', $captcha->parentGroup);

        $required = $component->params[1];
        self::assertSame('LIST', $required->type);
        self::assertTrue($required->multiple);
        self::assertStringContainsString('NONE', (string)$required->valuesRepr);
    }

    public function testRuntimeComputedDefaultIsMarkedUnresolved(): void
    {
        [$extraction] = $this->extractor->extract(<<<'PHP'
        <?php
        $arComponentParameters = array(
            "PARAMETERS" => array(
                "EMAIL_TO" => Array(
                    "TYPE" => "STRING",
                    "DEFAULT" => htmlspecialcharsbx(COption::GetOptionString("main", "email_from")),
                ),
            ),
        );
        PHP, self::PATH);

        self::assertNotNull($extraction);
        $component = $extraction->component;
        self::assertNotNull($component);

        $param = $component->params[0];

        self::assertSame('EMAIL_TO', $param->code);
        self::assertSame('STRING', $param->type);
        self::assertFalse(
            $param->isDefaultResolved,
            'Значение вычисляется в рантайме и обязано быть помечено, а не показано как отсутствующее',
        );
        self::assertNull($param->defaultRepr);
        self::assertSame(1, $component->unresolvedCount());
    }

    public function testComponentIsRegisteredEvenWhenParametersAreDynamic(): void
    {
        [$extraction] = $this->extractor->extract(<<<'PHP'
        <?php
        $arComponentParameters = buildParametersSomehow();
        PHP, self::PATH);

        self::assertNotNull($extraction);
        $component = $extraction->component;

        self::assertNotNull(
            $component,
            'Компонент обязан попасть в индекс даже когда параметры не извлеклись: ' .
            'иначе он исчезает без следа, а агент получает «не найдено» на существующий компонент',
        );
        self::assertFalse($component->paramsResolved);
        self::assertSame([], $component->params);
    }

    public function testLanguageFilesAreNotComponents(): void
    {
        self::assertNull(ComponentExtractor::identityFromPath(
            'modules/vote/install/components/bitrix/voting.list/lang/ru/.parameters.php'
        ));
    }

    public function testTemplateParametersAreNotComponents(): void
    {
        // Параметры ШАБЛОНА лежат глубже и компонентом не являются.
        self::assertNull(ComponentExtractor::identityFromPath(
            'modules/main/install/components/bitrix/news/templates/.default/.parameters.php'
        ));
    }

    public function testGadgetParametersAreNotComponents(): void
    {
        self::assertNull(ComponentExtractor::identityFromPath(
            'modules/sale/install/gadgets/bitrix/admin_orders/.parameters.php'
        ));
    }

    public function testRegularFileIsNotAComponent(): void
    {
        [$extraction] = $this->extractor->extract(
            "<?php\nclass Foo {}\n",
            'modules/main/lib/foo.php',
        );

        self::assertNotNull($extraction);
        self::assertNull($extraction->component);
    }
}
