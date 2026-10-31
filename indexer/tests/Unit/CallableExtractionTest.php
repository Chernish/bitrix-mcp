<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Unit;

use BitrixMcp\Indexer\Extract\FileExtractor;
use PHPUnit\Framework\TestCase;

final class CallableExtractionTest extends TestCase
{
    public function testFunctionsConstantsAndUsagesComeFromAst(): void
    {
        $code = <<<'PHP'
<?php
namespace Demo;

class Service
{
    public const MODE = 'safe';

    public function run(): void
    {
        helper('x');
        self::build();
        $this->finish();
        $class::dynamicBuild();
    }
}

function helper(string $value = 'x'): string
{
    return strtoupper($value);
}
PHP;

        [$extraction, $error] = (new FileExtractor())->extract($code, 'modules/demo/lib/service.php');

        self::assertNull($error);
        self::assertNotNull($extraction);
        self::assertCount(1, $extraction->functions);
        self::assertSame('\Demo\helper', $extraction->functions[0]->fqn);
        self::assertSame('function helper(string $value = \'x\'): string', $extraction->functions[0]->signature);
        self::assertCount(1, $extraction->symbols[0]->constants);
        self::assertSame('MODE', $extraction->symbols[0]->constants[0]->name);
        self::assertSame("'safe'", $extraction->symbols[0]->constants[0]->valueRepr);

        $byMember = [];
        foreach ($extraction->usages as $usage) {
            $byMember[$usage->memberName] = $usage;
        }

        self::assertSame('\Demo\helper', $byMember['helper']->targetFqn);
        self::assertSame('\Demo\Service', $byMember['build']->targetFqn);
        self::assertTrue($byMember['finish']->isDynamic);
        self::assertSame('\Demo\Service', $byMember['finish']->contextFqn);
        self::assertSame('run', $byMember['finish']->contextMember);
        self::assertNull($byMember['dynamicBuild']->targetFqn);
        self::assertTrue($byMember['dynamicBuild']->isDynamic);
    }

    public function testSignaturesKeepSpecialTypesAndReturnsByReference(): void
    {
        [$extraction, $error] = (new FileExtractor())->extract(<<<'PHP'
        <?php
        namespace Demo;

        class ReferenceService
        {
            public function &current(): static
            {
                return $this;
            }

            protected function &copy(self $value): self
            {
                return $this;
            }
        }

        function &cachedInstance(): \ArrayObject
        {
            return $GLOBALS['instance'];
        }
        PHP);

        self::assertNull($error);
        self::assertNotNull($extraction);
        self::assertSame(
            'public function &current(): static',
            $extraction->symbols[0]->methods[0]->signature,
        );
        self::assertSame(
            'protected function &copy(self $value): self',
            $extraction->symbols[0]->methods[1]->signature,
        );
        self::assertSame('self', $extraction->symbols[0]->methods[1]->returnType);
        self::assertSame(
            'function &cachedInstance(): \ArrayObject',
            $extraction->functions[0]->signature,
        );
    }

    public function testDnfTypesKeepRequiredParentheses(): void
    {
        [$extraction, $error] = (new FileExtractor())->extract(<<<'PHP'
        <?php
        class DnfService
        {
            public function convert((\A&\B)|\C $value): (\A&\B)|\C
            {
                return $value;
            }
        }
        PHP);

        self::assertNull($error);
        self::assertNotNull($extraction);
        $method = $extraction->symbols[0]->methods[0];
        self::assertSame(
            'public function convert((\A&\B)|\C $value): (\A&\B)|\C',
            $method->signature,
        );
        self::assertSame('(\A&\B)|\C', $method->parameters[0]->type);
        self::assertSame('(\A&\B)|\C', $method->returnType);
    }
}
