<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Docs;

use RuntimeException;

/** Получает воспроизводимый локальный снимок официального Git-репозитория. */
final class GitDocsRepository
{
    public function sync(string $source, string $ref, string $cachePath): DocsRepositorySnapshot
    {
        $local = realpath($source);
        if ($local !== false && is_dir($local)) {
            $this->requirePages($local);

            return new DocsRepositorySnapshot($local, $this->revision($local), $local);
        }

        if (is_dir($cachePath . DIRECTORY_SEPARATOR . '.git')) {
            $this->run(['git', '-C', $cachePath, 'fetch', '--depth=1', 'origin', $ref]);
            $this->run(['git', '-C', $cachePath, 'checkout', '--detach', '--force', 'FETCH_HEAD']);
        } elseif (file_exists($cachePath)) {
            throw new RuntimeException("кэш документации не является Git-репозиторием: {$cachePath}");
        } else {
            $parent = dirname($cachePath);
            if (!is_dir($parent) && !mkdir($parent, 0o775, true) && !is_dir($parent)) {
                throw new RuntimeException("не удалось создать каталог кэша: {$parent}");
            }

            $this->run(['git', 'clone', '--depth=1', '--branch', $ref, '--single-branch', $source, $cachePath]);
        }

        $this->requirePages($cachePath);

        return new DocsRepositorySnapshot($cachePath, $this->revision($cachePath), $source);
    }

    private function requirePages(string $path): void
    {
        if (!is_dir($path . DIRECTORY_SEPARATOR . 'pages')) {
            throw new RuntimeException("в репозитории документации нет каталога pages: {$path}");
        }
    }

    private function revision(string $path): string
    {
        return trim($this->run(['git', '-C', $path, 'rev-parse', 'HEAD']));
    }

    /** @param list<string> $command */
    private function run(array $command): string
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('не удалось запустить git');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $message = trim(is_string($stderr) ? $stderr : '');
            throw new RuntimeException('git завершился с ошибкой: ' . ($message !== '' ? $message : $exitCode));
        }

        return is_string($stdout) ? $stdout : '';
    }
}
