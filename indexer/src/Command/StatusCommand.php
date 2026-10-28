<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Command;

use BitrixMcp\Indexer\Core\Config;
use BitrixMcp\Indexer\Core\ModuleVersionReader;
use BitrixMcp\Indexer\ExitCode;
use BitrixMcp\Indexer\Storage\Database;
use BitrixMcp\Indexer\Storage\SchemaVersionGuard;
use PDO;

/**
 * Состояние индекса.
 *
 * Отдельно сообщает о двух видах непригодности, которые легко спутать:
 *  - индекс устарел относительно ядра (сменился SM_VERSION);
 *  - индекс построен при выключенном short_open_tag и потому неполон.
 * Второе опаснее: устаревший индекс врёт про версии, а неполный — про само
 * существование символов.
 */
final class StatusCommand
{
    /** @param array<string, string> $cli */
    public function run(array $cli, Reporter $out): ExitCode
    {
        $config = Config::resolve($cli);

        if (!is_file($config->indexPath)) {
            $out->line("Индекс не найден: {$config->indexPath}");
            $out->line('Постройте его: make build');

            return ExitCode::ConfigError;
        }

        $pdo = Database::open($config->indexPath);
        SchemaVersionGuard::enforce($pdo, $config->indexPath);

        $snapshotStmt = $pdo->query('SELECT * FROM snapshot WHERE id = 1');
        /** @var array<string, mixed> $snapshot */
        $snapshot = $snapshotStmt === false ? [] : ($snapshotStmt->fetch() ?: []);
        $counts = $this->counts($pdo);

        $currentBuild = (new ModuleVersionReader())->readBuildVersion($config->coreRoot());
        $indexedSm = $snapshot['sm_version'] ?? null;
        $currentSm = $currentBuild?->smVersion;

        $stale = $indexedSm !== null && $currentSm !== null && $indexedSm !== $currentSm;
        $staleReason = $stale
            ? "sm_version изменился: {$indexedSm} -> {$currentSm}"
            : null;

        $shortTagOk = (int)($snapshot['short_open_tag_enabled'] ?? 0) === 1;

        $payload = [
            'core_root' => $config->coreRoot()->path,
            'index_path' => $config->indexPath,
            'sm_version' => $indexedSm,
            'sm_version_date' => $snapshot['sm_version_date'] ?? null,
            'indexed_at' => $snapshot['indexed_at'] ?? null,
            'schema_version' => (int)($snapshot['schema_version'] ?? 0),
            'short_open_tag_enabled' => $shortTagOk,
            'counts' => $counts,
            'parse_errors' => [
                'count' => (int)($snapshot['parse_error_count'] ?? 0),
                'share' => $counts['files'] > 0
                    ? round((int)($snapshot['parse_error_count'] ?? 0) / $counts['files'], 5)
                    : 0.0,
            ],
            'stale' => $stale,
            'stale_reason' => $staleReason,
        ];

        if (isset($cli['deep'])) {
            $map = (new ModuleVersionReader())->readAll($config->coreRoot());
            $payload['module_versions'] = array_map(
                static fn($m) => $m->version,
                $map,
            );
            $payload['modules_without_version'] = array_keys(
                array_filter($map, static fn($m) => !$m->isKnown())
            );
        }

        if (isset($cli['json'])) {
            $out->json($payload);

            return ExitCode::Success;
        }

        $this->renderHuman($out, $payload);

        return ExitCode::Success;
    }

    /** @return array<string, int> */
    private function counts(PDO $pdo): array
    {
        $result = [];

        foreach (
            ['modules' => 'module', 'files' => 'file', 'symbols' => 'symbol',
                  'methods' => 'method', 'aliases' => 'symbol_alias'] as $key => $table
        ) {
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
            $result[$key] = $stmt === false ? 0 : (int)($stmt->fetchColumn() ?: 0);
        }

        return $result;
    }

    /** @param array<string, mixed> $p */
    private function renderHuman(Reporter $out, array $p): void
    {
        $out->line('Ядро:       ' . $p['core_root']);
        $out->line('Индекс:     ' . $p['index_path']);
        $out->line(sprintf('Сборка:     SM_VERSION %s от %s', $p['sm_version'] ?? '—', $p['sm_version_date'] ?? '—'));
        $out->line('Построен:   ' . ($p['indexed_at'] ?? '—'));
        $out->line('');

        /** @var array<string, int> $c */
        $c = $p['counts'];
        $out->line(sprintf(
            'Модулей: %d   Файлов: %d   Символов: %d   Методов: %d   Псевдонимов: %d',
            $c['modules'],
            $c['files'],
            $c['symbols'],
            $c['methods'],
            $c['aliases'],
        ));

        /** @var array{count: int, share: float} $pe */
        $pe = $p['parse_errors'];
        $out->line(sprintf('Ошибок разбора: %d (%.3f%%)', $pe['count'], $pe['share'] * 100));
        $out->line('');

        if ($p['short_open_tag_enabled'] !== true) {
            $out->line('❌ ИНДЕКС НЕПРИГОДЕН: построен при выключенном short_open_tag.');
            $out->line('   41% файлов ядра потеряно молча. Пересоберите: make build');
        }

        if ($p['stale'] === true) {
            $out->line('⚠️  ИНДЕКС УСТАРЕЛ: ' . $p['stale_reason']);
            $out->line('   Обновите: make update');
        }

        if ($p['short_open_tag_enabled'] === true && $p['stale'] !== true) {
            $out->line('✓ Индекс актуален.');
        }
    }
}
