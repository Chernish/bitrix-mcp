<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Preflight;

use BitrixMcp\Indexer\ExitCode;
use RuntimeException;

/**
 * Отказ на этапе предварительной проверки.
 *
 * Исключение, а не прямой exit(), — чтобы проверки оставались тестируемыми:
 * тест обязан наблюдать отказ, а не завершать процесс PHPUnit.
 * Преобразование в код возврата выполняет точка входа CLI.
 */
final class PreflightFailure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ExitCode $exitCode,
    ) {
        parent::__construct($message, $exitCode->value);
    }
}
