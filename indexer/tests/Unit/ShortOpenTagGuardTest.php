<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Unit;

use BitrixMcp\Indexer\ExitCode;
use BitrixMcp\Indexer\Preflight\PreflightFailure;
use BitrixMcp\Indexer\Preflight\ShortOpenTagGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ShortOpenTagGuard::class)]
final class ShortOpenTagGuardTest extends TestCase
{
    public function testRefusesWhenShortOpenTagDisabled(): void
    {
        $guard = new ShortOpenTagGuard(override: false);

        $this->expectException(PreflightFailure::class);

        try {
            $guard->enforce();
        } catch (PreflightFailure $e) {
            self::assertSame(
                ExitCode::ShortOpenTagDisabled,
                $e->exitCode,
                'Отказ обязан нести код 2, чтобы вызывающая сторона отличала его от ошибки конфигурации',
            );
            throw $e;
        }
    }

    public function testPassesWhenShortOpenTagEnabled(): void
    {
        $guard = new ShortOpenTagGuard(override: true);

        $guard->enforce();

        $this->expectNotToPerformAssertions();
    }

    public function testMessageNamesTheConcreteRemedy(): void
    {
        $message = ShortOpenTagGuard::message();

        // Сообщение должно объяснять не только «что», но и «почему это опасно»:
        // без этого следующий разработчик отключит проверку как назойливую.
        self::assertStringContainsString('short_open_tag=1', $message);
        self::assertStringContainsString('молча', $message);
    }

    public function testReadsRealIniSettingWhenNoOverride(): void
    {
        $guard = new ShortOpenTagGuard();

        self::assertSame(
            filter_var(ini_get('short_open_tag'), FILTER_VALIDATE_BOOLEAN),
            $guard->isEnabled(),
        );
    }
}
