<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Command;

/**
 * Вывод диагностики.
 *
 * Всё идёт в stderr. stdout остаётся свободным под машиночитаемый вывод
 * (`status --json`), а в контексте MCP — под поток сообщений протокола,
 * который ломается от любой посторонней строки (R-012).
 */
final class Reporter
{
    /** @var resource */
    private $stream;

    private bool $progressActive = false;

    public function __construct(mixed $stream = null)
    {
        $this->stream = is_resource($stream) ? $stream : STDERR;
    }

    public function line(string $text = ''): void
    {
        $this->progressDone();
        fwrite($this->stream, $text . PHP_EOL);
    }

    /** Перезаписываемая строка прогресса. */
    public function progress(string $text): void
    {
        fwrite($this->stream, "\r\033[K" . $text);
        $this->progressActive = true;
    }

    public function progressDone(): void
    {
        if ($this->progressActive) {
            fwrite($this->stream, PHP_EOL);
            $this->progressActive = false;
        }
    }

    /** Машиночитаемый вывод идёт в stdout, а не сюда. */
    public function json(mixed $data): void
    {
        fwrite(STDOUT, json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) . PHP_EOL);
    }
}
