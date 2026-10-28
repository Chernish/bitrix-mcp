<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer;

/**
 * Коды возврата CLI по contracts/indexer-cli.md.
 *
 * Коды различают причины отказа, чтобы вызывающая сторона могла отличить
 * ошибку настройки от непригодного окружения и от сетевого сбоя.
 */
enum ExitCode: int
{
    case Success = 0;
    case ConfigError = 1;
    case ShortOpenTagDisabled = 2;
    case ParseErrorThreshold = 3;
    case DocsNetworkError = 4;
    case SchemaMismatch = 5;
}
