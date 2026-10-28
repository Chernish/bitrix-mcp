<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Command;

use BitrixMcp\Indexer\Core\Config;
use BitrixMcp\Indexer\Docs\DocsCrawler;
use BitrixMcp\Indexer\Docs\GitDocsImporter;
use BitrixMcp\Indexer\Docs\GitDocsRepository;
use BitrixMcp\Indexer\Docs\HttpFetcher;
use BitrixMcp\Indexer\ExitCode;
use BitrixMcp\Indexer\Preflight\PreflightFailure;
use BitrixMcp\Indexer\Storage\Database;
use BitrixMcp\Indexer\Storage\Schema;
use BitrixMcp\Indexer\Storage\SchemaVersionGuard;

/**
 * Сбор концептуальной документации.
 *
 * Не требует `short_open_tag`: команда не разбирает PHP. Требование
 * относится к индексации кода, и распространять его сюда значило бы
 * запрещать работу без причины.
 *
 * Недоступность сайта завершается кодом 4 и НЕ повреждает уже собранный
 * индекс кода: документация — обогащение, а не основа.
 */
final class DocsCommand
{
    /** @param array<string, string> $cli */
    public function run(array $cli, Reporter $out): ExitCode
    {
        // Ядро этой команде не нужно: она собирает внешнюю документацию.
        $config = Config::resolve($cli, null, requireCore: false);

        if (!is_file($config->indexPath)) {
            throw new PreflightFailure(
                "ОШИБКА: индекс не найден: {$config->indexPath}\n"
                . 'Сначала постройте индекс кода: make build',
                ExitCode::ConfigError,
            );
        }

        $pdo = Database::open($config->indexPath);
        SchemaVersionGuard::enforce($pdo, $config->indexPath);

        $force = isset($cli['force']);
        $started = microtime(true);

        $out->line('Сбор документации');
        if ($config->docsSource === 'git') {
            return $this->fromGit($pdo, $config, $out, $force, $started);
        }

        if ($config->docsSource !== 'web') {
            throw new PreflightFailure(
                "ОШИБКА: неизвестный источник документации: {$config->docsSource}. Ожидается git или web.",
                ExitCode::ConfigError,
            );
        }

        return $this->fromWeb($pdo, $config, $out, $force, $started);
    }

    private function fromWeb(
        \PDO $pdo,
        Config $config,
        Reporter $out,
        bool $force,
        float $started,
    ): ExitCode {
        $out->line('  источник: web ' . $config->docsUrl);
        $out->line('  пауза:    ' . $config->docsDelayMs . ' мс');
        if ($force) {
            $out->line('  режим:    принудительный, HTTP-кэш игнорируется');
        }

        $crawler = new DocsCrawler(new HttpFetcher(delayMs: $config->docsDelayMs));

        $pdo->beginTransaction();
        $result = $crawler->crawl($pdo, $config->docsUrl, $out, $force);
        $pdo->commit();

        if ($result['error'] !== null) {
            $out->line('');
            $out->line('ОШИБКА: ' . $result['error']);
            $out->line('Индекс кода не повреждён и продолжает работать.');

            return ExitCode::DocsNetworkError;
        }

        Schema::rebuildDocsIndex($pdo);

        $stmt = $pdo->prepare(
            'UPDATE snapshot
             SET docs_crawled_at = ?, docs_source = ?, docs_source_revision = NULL
             WHERE id = 1'
        );
        $stmt->execute([gmdate('c'), $config->docsUrl]);

        $out->line('');
        $out->line(sprintf(
            'Обойдено адресов: %d — загружено %d, без изменений %d, пропущено по robots.txt %d, недоступно %d',
            $result['visited'],
            $result['fetched'],
            $result['unchanged'],
            $result['skipped'],
            $result['failed'],
        ));
        $out->line(sprintf('Время: %.1f с', microtime(true) - $started));

        // Часть страниц может быть недоступна временно — это не повод считать
        // весь сбор проваленным, но и молчать об этом нельзя.
        if ($result['failed'] > 0 && $result['fetched'] === 0 && $result['unchanged'] === 0) {
            return ExitCode::DocsNetworkError;
        }

        return ExitCode::Success;
    }

    private function fromGit(
        \PDO $pdo,
        Config $config,
        Reporter $out,
        bool $force,
        float $started,
    ): ExitCode {
        $out->line('  источник: git ' . $config->docsRepo);
        $out->line('  ветка:    ' . $config->docsRef);
        $out->line('  кэш:      ' . $config->docsCache);
        if ($force) {
            $out->line('  режим:    принудительное переизвлечение');
        }

        try {
            $snapshot = (new GitDocsRepository())->sync(
                $config->docsRepo,
                $config->docsRef,
                $config->docsCache,
            );
            $out->line('  commit:   ' . $snapshot->revision);

            $pdo->beginTransaction();
            $result = (new GitDocsImporter())->import($pdo, $snapshot, $force);
            Schema::rebuildDocsIndex($pdo);
            $stmt = $pdo->prepare(
                'UPDATE snapshot
                 SET docs_crawled_at = ?, docs_source = ?, docs_source_revision = ?
                 WHERE id = 1'
            );
            $stmt->execute([gmdate('c'), $snapshot->source, $snapshot->revision]);
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $out->line('');
            $out->line('ОШИБКА: ' . $error->getMessage());
            $out->line('Существующая документация и индекс кода не повреждены.');
            $out->line('Для аварийного обхода сайта используйте --docs-source=web.');

            return ExitCode::DocsNetworkError;
        }

        $out->line('');
        $out->line(sprintf(
            'Страниц: %d — обновлено %d, без изменений %d, удалено устаревших %d',
            $result['pages'],
            $result['changed'],
            $result['unchanged'],
            $result['removed'],
        ));
        $out->line(sprintf('Время: %.1f с', microtime(true) - $started));

        return ExitCode::Success;
    }
}
