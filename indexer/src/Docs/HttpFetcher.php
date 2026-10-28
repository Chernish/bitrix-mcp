<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Docs;

/**
 * HTTP-клиент для сбора документации.
 *
 * Реализован прямыми запросами через curl намеренно: штатный механизм
 * загрузки веб-страниц агента к домену документации заблокирован корпоративной
 * сетевой политикой, тогда как прямые запросы проходят (R-011). Поэтому
 * краулер обязан жить в коде индексатора и не может опираться на
 * инструментарий агента.
 *
 * Пауза между запросами выдерживается самим клиентом, а не вызывающим кодом:
 * так её нельзя забыть при добавлении нового места обхода.
 */
final class HttpFetcher
{
    private float $lastRequestAt = 0.0;

    public function __construct(
        private readonly int $delayMs = 500,
        private readonly int $timeoutSeconds = 20,
        /** @var non-empty-string */
        private readonly string $userAgent = 'bitrix-mcp-indexer/0.1 (+local dev tool)',
    ) {
    }

    /**
     * @param string|null $etag значение из кэша для условного запроса
     * @return array{status: int, body: string|null, etag: string|null, error: string|null}
     */
    public function get(string $url, ?string $etag = null): array
    {
        $this->respectDelay();

        $ch = curl_init($url);

        if ($ch === false) {
            return ['status' => 0, 'body' => null, 'etag' => null, 'error' => 'не удалось инициализировать curl'];
        }

        $headers = ['Accept: text/html,application/xhtml+xml,application/xml'];

        if ($etag !== null && $etag !== '') {
            $headers[] = 'If-None-Match: ' . $etag;
        }

        $responseHeaders = [];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($_, string $header) use (&$responseHeaders): int {
            $parts = explode(':', $header, 2);

            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($header);
        });

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;

        curl_close($ch);

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : null,
            'etag' => $responseHeaders['etag'] ?? null,
            'error' => $error,
        ];
    }

    private function respectDelay(): void
    {
        if ($this->lastRequestAt > 0.0) {
            $elapsedMs = (microtime(true) - $this->lastRequestAt) * 1000;
            $remaining = $this->delayMs - $elapsedMs;

            if ($remaining > 0) {
                usleep((int)round($remaining * 1000));
            }
        }

        $this->lastRequestAt = microtime(true);
    }
}
