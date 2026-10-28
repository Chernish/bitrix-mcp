<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Command;

use BitrixMcp\Indexer\Core\Config;
use BitrixMcp\Indexer\ExitCode;
use BitrixMcp\Indexer\Preflight\PreflightFailure;
use BitrixMcp\Indexer\Preflight\ShortOpenTagGuard;
use BitrixMcp\Indexer\Storage\SnapshotStore;

final class VersionedCommand
{
    public function __construct(
        private readonly BuildCommand|UpdateCommand|DocsCommand $command,
        private readonly ShortOpenTagGuard $guard = new ShortOpenTagGuard(),
    ) {
    }

    /** @param array<string, string> $cli */
    public function run(array $cli, Reporter $out): ExitCode
    {
        if (!$this->command instanceof DocsCommand) {
            $this->guard->enforce();
        }

        $config = Config::resolve($cli, null, requireCore: $this->command instanceof DocsCommand ? false : true);
        $activePath = $config->indexPath;

        if (!$this->command instanceof BuildCommand && !is_file($activePath)) {
            throw new PreflightFailure(
                "ОШИБКА: индекс не найден: {$activePath}\nСначала постройте индекс кода: make build",
                ExitCode::ConfigError,
            );
        }

        $store = new SnapshotStore($activePath);
        $staging = $store->stage();
        $stagedCli = $cli;
        $stagedCli['index'] = $staging;

        try {
            $code = $this->command->run($stagedCli, $out);
            if ($code !== ExitCode::Success) {
                return $code;
            }

            $metadata = $store->publish($staging);
            $out->line('');
            $out->line("Снимок: {$metadata['id']}");
            $out->line("Активный индекс: {$activePath}");

            return ExitCode::Success;
        } finally {
            $store->discard($staging);
        }
    }
}
