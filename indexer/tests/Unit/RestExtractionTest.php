<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Unit;

use BitrixMcp\Indexer\Extract\FileExtractor;
use PHPUnit\Framework\TestCase;

final class RestExtractionTest extends TestCase
{
    public function testHandlersComeFromDeclaredCallbacks(): void
    {
        [$extraction, $error] = (new FileExtractor())->extract(<<<'PHP'
        <?php
        namespace Demo;

        use Vendor\Rest\ExternalHandler;

        class Service
        {
            public static function OnRestServiceBuildDescription(): array
            {
                $unused = function () {
                    return ['demo.hidden' => [__CLASS__, 'hidden']];
                };

                return ['demo' => [
                    'demo.one' => [__CLASS__, 'one'],
                    'demo.two' => ['callback' => [ExternalHandler::class, 'two'], 'options' => []],
                    'demo.legacy' => ['CExternalLegacy', 'run'],
                    'demo.dynamic' => $GLOBALS['handler'],
                ]];
            }
        }
        PHP);

        self::assertNull($error);
        self::assertNotNull($extraction);
        $methods = [];
        foreach ($extraction->restMethods as $method) {
            $methods[$method['name']] = $method['handler'];
        }

        self::assertSame([
            'demo.one' => '\Demo\Service::one',
            'demo.two' => '\Vendor\Rest\ExternalHandler::two',
            'demo.legacy' => '\CExternalLegacy::run',
            'demo.dynamic' => null,
        ], $methods);
    }

    public function testOnlyReturnedDescriptionIsPublished(): void
    {
        [$extraction, $error] = (new FileExtractor())->extract(<<<'PHP'
        <?php
        class AuditRestService
        {
            public static function OnRestServiceBuildDescription(): array
            {
                $methods = ['audit.real' => [__CLASS__, 'real']];
                $debug = ['audit.debug' => [__CLASS__, 'debug']];

                return ['audit' => $methods];
            }
        }
        PHP);

        self::assertNull($error);
        self::assertNotNull($extraction);
        self::assertSame([
            ['name' => 'audit.real', 'handler' => '\AuditRestService::real', 'line' => 6],
        ], $extraction->restMethods);
    }
}
