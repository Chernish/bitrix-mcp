<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Command;

use BitrixMcp\Indexer\Core\Config;
use BitrixMcp\Indexer\ExitCode;
use BitrixMcp\Indexer\Preflight\PreflightFailure;
use BitrixMcp\Indexer\Storage\SnapshotStore;

final class SnapshotsCommand
{
    /** @param array<string, string> $cli */
    public function list(array $cli, Reporter $out): ExitCode
    {
        $data = $this->store($cli)->list();
        if (isset($cli['json'])) {
            $out->json($data);

            return ExitCode::Success;
        }

        $out->line('Снимки ядра');
        $out->line('Активный: ' . ($data['active_id'] ?? 'вне каталога'));
        $out->line(sprintf(
            'Всего: %d (%.1f МБ)',
            count($data['snapshots']),
            $data['total_bytes'] / 1048576,
        ));
        $out->line('');

        foreach ($data['snapshots'] as $metadata) {
            $active = $metadata['id'] === $data['active_id'] ? '*' : ' ';
            $out->line(sprintf(
                '%s %s  %s  %s  %.1f МБ',
                $active,
                $metadata['id'],
                $metadata['sm_version'] ?? 'unknown',
                $metadata['indexed_at'],
                $metadata['size_bytes'] / 1048576,
            ));
            $out->line('    docs: ' . ($metadata['docs_source_revision'] ?? '—'));
        }

        return ExitCode::Success;
    }

    /** @param array<string, string> $cli */
    public function archive(array $cli, Reporter $out): ExitCode
    {
        $metadata = $this->store($cli)->archiveCurrent();
        $out->line("Снимок архивирован: {$metadata['id']}");
        $out->line('SM_VERSION: ' . ($metadata['sm_version'] ?? 'unknown'));

        return ExitCode::Success;
    }

    /** @param array<string, string> $cli */
    public function activate(array $cli, Reporter $out): ExitCode
    {
        $metadata = $this->store($cli)->activate($this->id($cli));
        $out->line("Активирован снимок: {$metadata['id']}");
        $out->line('SM_VERSION: ' . ($metadata['sm_version'] ?? 'unknown'));

        return ExitCode::Success;
    }

    /** @param array<string, string> $cli */
    public function delete(array $cli, Reporter $out): ExitCode
    {
        $id = $this->id($cli);
        $this->store($cli)->delete($id);
        $out->line("Удалён снимок: {$id}");

        return ExitCode::Success;
    }

    /** @param array<string, string> $cli */
    private function store(array $cli): SnapshotStore
    {
        return new SnapshotStore(Config::resolve($cli, null, requireCore: false)->indexPath);
    }

    /** @param array<string, string> $cli */
    private function id(array $cli): string
    {
        $id = $cli['id'] ?? '';
        if ($id === '') {
            throw new PreflightFailure('ОШИБКА: укажите снимок: --id=SNAPSHOT_ID', ExitCode::ConfigError);
        }

        return $id;
    }
}
