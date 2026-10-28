<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Core;

use BitrixMcp\Indexer\ExitCode;
use BitrixMcp\Indexer\Preflight\PreflightFailure;

/**
 * Конфигурация индексатора.
 *
 * Приоритет источников: аргументы CLI → переменные окружения → умолчания.
 * Захардкоженные пути запрещены (FR-014).
 *
 * У корня ядра умолчания нет намеренно: молчаливая индексация не того каталога
 * хуже, чем отказ с требованием указать путь.
 */
final readonly class Config
{
    public const DEFAULT_INDEX_PATH = './index.sqlite';
    public const DEFAULT_DOCS_URL = 'https://docs.1c-bitrix.ru';
    public const DEFAULT_DOCS_DELAY_MS = 500;
    public const DEFAULT_DOCS_SOURCE = 'git';
    public const DEFAULT_DOCS_REPO = 'https://github.com/bitrix-tools/framework-docs.git';
    public const DEFAULT_DOCS_REF = 'main';
    public const DEFAULT_DOCS_CACHE = './.docs-cache/framework-docs';

    /** @param list<IndexRoot> $roots первый элемент — ядро, если он требуется */
    public function __construct(
        public array $roots,
        public string $indexPath,
        public string $docsUrl,
        public int $docsDelayMs,
        public string $docsSource,
        public string $docsRepo,
        public string $docsRef,
        public string $docsCache,
    ) {
    }

    public function coreRoot(): IndexRoot
    {
        $root = $this->roots[0] ?? null;

        if ($root === null) {
            throw new PreflightFailure(
                'ОШИБКА: операция требует корня ядра, но он не задан.',
                ExitCode::ConfigError,
            );
        }

        return $root;
    }

    /**
     * @param array<string, string> $cli аргументы вида ['core' => '/path', 'index' => '...']
     * @param array<string, string>|null $env null — читать реальное окружение
     * @param bool $requireCore false для команд, не работающих с ядром
     *        (сбор документации): требовать путь, который не используется,
     *        значит создавать препятствие на пустом месте
     * @throws PreflightFailure если корень ядра требуется, но не указан
     */
    public static function resolve(array $cli = [], ?array $env = null, bool $requireCore = true): self
    {
        $env ??= getenv();

        $coreRaw = $cli['core']
            ?? ($env['BITRIX_CORE_ROOT'] ?? null);

        if (!$requireCore && ($coreRaw === null || trim($coreRaw) === '')) {
            return new self(
                roots: [],
                indexPath: $cli['index'] ?? ($env['BITRIX_MCP_INDEX'] ?? self::DEFAULT_INDEX_PATH),
                docsUrl: $cli['docs-url'] ?? ($env['BITRIX_DOCS_URL'] ?? self::DEFAULT_DOCS_URL),
                docsDelayMs: (int)($cli['docs-delay'] ?? ($env['BITRIX_DOCS_DELAY_MS'] ?? self::DEFAULT_DOCS_DELAY_MS)),
                docsSource: $cli['docs-source'] ?? ($env['BITRIX_DOCS_SOURCE'] ?? self::DEFAULT_DOCS_SOURCE),
                docsRepo: $cli['docs-repo'] ?? ($env['BITRIX_DOCS_REPO'] ?? self::DEFAULT_DOCS_REPO),
                docsRef: $cli['docs-ref'] ?? ($env['BITRIX_DOCS_REF'] ?? self::DEFAULT_DOCS_REF),
                docsCache: $cli['docs-cache'] ?? ($env['BITRIX_DOCS_CACHE'] ?? self::DEFAULT_DOCS_CACHE),
            );
        }

        if ($coreRaw === null || trim($coreRaw) === '') {
            throw new PreflightFailure(
                "ОШИБКА: не указан корень ядра Битрикса.\n\n"
                . "Укажите его одним из способов:\n"
                . "    --core=/path/to/bitrix\n"
                . "    BITRIX_CORE_ROOT=/path/to/bitrix\n\n"
                . 'Значения по умолчанию нет намеренно: молчаливая индексация '
                . "не того каталога хуже отказа.",
                ExitCode::ConfigError,
            );
        }

        $core = self::requireDirectory($coreRaw, 'корень ядра');

        if (!is_dir($core . DIRECTORY_SEPARATOR . 'modules')) {
            throw new PreflightFailure(
                "ОШИБКА: в каталоге {$core} нет подкаталога modules.\n"
                . 'Ожидается корень ядра Битрикса (каталог bitrix), а не корень сайта.',
                ExitCode::ConfigError,
            );
        }

        $roots = [IndexRoot::core($core)];

        $extraRaw = $cli['extra-roots'] ?? ($env['BITRIX_EXTRA_ROOTS'] ?? '');
        foreach (self::splitList($extraRaw) as $i => $extra) {
            $path = self::requireDirectory($extra, "дополнительный корень #" . ($i + 1));
            $roots[] = IndexRoot::extra($path, 'extra:' . basename($path));
        }

        return new self(
            roots: $roots,
            indexPath: $cli['index']
                ?? ($env['BITRIX_MCP_INDEX'] ?? self::DEFAULT_INDEX_PATH),
            docsUrl: $cli['docs-url']
                ?? ($env['BITRIX_DOCS_URL'] ?? self::DEFAULT_DOCS_URL),
            docsDelayMs: (int)($cli['docs-delay']
                ?? ($env['BITRIX_DOCS_DELAY_MS'] ?? self::DEFAULT_DOCS_DELAY_MS)),
            docsSource: $cli['docs-source'] ?? ($env['BITRIX_DOCS_SOURCE'] ?? self::DEFAULT_DOCS_SOURCE),
            docsRepo: $cli['docs-repo'] ?? ($env['BITRIX_DOCS_REPO'] ?? self::DEFAULT_DOCS_REPO),
            docsRef: $cli['docs-ref'] ?? ($env['BITRIX_DOCS_REF'] ?? self::DEFAULT_DOCS_REF),
            docsCache: $cli['docs-cache'] ?? ($env['BITRIX_DOCS_CACHE'] ?? self::DEFAULT_DOCS_CACHE),
        );
    }

    /** @return list<string> */
    private static function splitList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[:,]/', $raw) ?: [];

        return array_values(array_filter(array_map(trim(...), $parts), static fn($p) => $p !== ''));
    }

    private static function requireDirectory(string $path, string $what): string
    {
        $real = realpath($path);

        if ($real === false || !is_dir($real)) {
            throw new PreflightFailure(
                "ОШИБКА: {$what} недоступен: {$path}",
                ExitCode::ConfigError,
            );
        }

        if (!is_readable($real)) {
            throw new PreflightFailure(
                "ОШИБКА: нет прав на чтение: {$real}",
                ExitCode::ConfigError,
            );
        }

        return $real;
    }
}
