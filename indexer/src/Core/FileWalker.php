<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Core;

use FilesystemIterator;
use Generator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Обход дерева модулей.
 *
 * Обходит `<root>/modules/<code>/**` и выдаёт только PHP-файлы, у каждого из
 * которых код модуля известен из пути. Файлы вне модуля в выдачу не попадают:
 * благодаря этому запись сущности без версионной атрибуции невозможна
 * структурно, а не по договорённости (Принцип II).
 *
 * Обход принимает список корней, чтобы подключение дополнительного корня
 * (например, кастомного кода проекта) оставалось настройкой, а не переработкой
 * модели владения (FR-014).
 */
final class FileWalker
{
    /**
     * Каталоги composer-зависимостей модуля.
     *
     * Исключается ТОЛЬКО `modules/<code>/vendor/` — каталог, который composer
     * создаёт на верхнем уровне модуля. Это сторонний код (geoip2, protobuf,
     * php-parser), и в индексе API Битрикса ему не место: агент, ищущий класс,
     * получал бы среди результатов `\PhpParser\Node\Scalar\DNumber`.
     *
     * Исключать любой каталог с именем `vendor` НЕЛЬЗЯ. В ядре есть собственный
     * код Битрикса в таких каталогах — например
     * `modules/calendar/lib/sync/vendor/vendorinterface.php`, где «vendor»
     * означает поставщика календарной синхронизации, а не composer. Замер
     * на целевом ядре: 437 файлов composer против 4 файлов своего кода.
     */
    private const MODULE_VENDOR_DIR = 'vendor';

    /** Каталоги, не содержащие индексируемого PHP-кода на любом уровне. */
    private const ALWAYS_SKIP = ['node_modules', '.git', '.idea'];

    private int $skippedVendorFiles = 0;

    /**
     * @param list<IndexRoot> $roots
     * @return Generator<WalkedFile>
     */
    public function walkAll(array $roots): Generator
    {
        foreach ($roots as $root) {
            yield from $this->walk($root);
        }
    }

    /**
     * @return Generator<WalkedFile>
     */
    public function walk(IndexRoot $root): Generator
    {
        $modulesDir = $root->path . DIRECTORY_SEPARATOR . 'modules';

        if (!is_dir($modulesDir)) {
            return;
        }

        foreach ($this->moduleCodes($modulesDir) as $code) {
            yield from $this->walkModule($root, $modulesDir, $code);
        }
    }

    /**
     * Сколько файлов пропущено как сторонние.
     *
     * Число сообщается в отчёте: молчаливое исключение читалось бы как
     * «обошли всё», а любое сужение охвата обязано быть видимым.
     */
    public function skippedVendorFiles(): int
    {
        return $this->skippedVendorFiles;
    }

    /** @return list<string> */
    public function moduleCodes(string $modulesDir): array
    {
        $entries = @scandir($modulesDir);

        if ($entries === false) {
            return [];
        }

        $codes = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            // Только каталоги. В modules/ лежат ещё updater.log, install.log
            // и .htaccess — подсчёт элементов каталога вместо каталогов
            // давал завышенное число модулей (research.md, R-013).
            if (is_dir($modulesDir . DIRECTORY_SEPARATOR . $entry)) {
                $codes[] = $entry;
            }
        }

        sort($codes);

        return $codes;
    }

    /**
     * @return Generator<WalkedFile>
     */
    private function walkModule(IndexRoot $root, string $modulesDir, string $code): Generator
    {
        $moduleDir = $modulesDir . DIRECTORY_SEPARATOR . $code;
        $vendorPrefix = $moduleDir . DIRECTORY_SEPARATOR . self::MODULE_VENDOR_DIR . DIRECTORY_SEPARATOR;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($moduleDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            if (!$info->isFile() || strtolower($info->getExtension()) !== 'php') {
                continue;
            }

            $abs = $info->getPathname();

            if (str_starts_with($abs, $vendorPrefix)) {
                $this->skippedVendorFiles++;
                continue;
            }

            if ($this->isInSkippedDirectory($abs, $moduleDir)) {
                continue;
            }

            yield new WalkedFile(
                root: $root,
                absPath: $abs,
                relPath: $root->relativize($abs),
                moduleCode: $code,
                size: (int)$info->getSize(),
                mtime: (int)$info->getMTime(),
            );
        }
    }

    private function isInSkippedDirectory(string $absPath, string $moduleDir): bool
    {
        $relative = substr($absPath, strlen($moduleDir) + 1);

        foreach (explode(DIRECTORY_SEPARATOR, $relative) as $segment) {
            if (in_array($segment, self::ALWAYS_SKIP, true)) {
                return true;
            }
        }

        return false;
    }
}
