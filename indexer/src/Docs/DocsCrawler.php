<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Docs;

use BitrixMcp\Indexer\Command\Reporter;
use PDO;

/**
 * Сбор документации обходом по ссылкам.
 *
 * ПОЧЕМУ НУЖНА НОРМАЛИЗАЦИЯ SITEMAP. На целевом сайте карта перечисляет
 * `/framework/architecture.html`, тогда как страница живёт по
 * `/pages/framework/architecture.html`. Поэтому каждый адрес карты получает
 * фактический префикс `/pages/` до постановки в очередь.
 *
 * Обход, доверявший бы только карте сайта, собрал бы ноль страниц и отчитался
 * бы «112 ошибок» — формально честно, практически бесполезно. Поэтому карта
 * используется как ИСТОЧНИК ЗАТРАВКИ, а основной механизм — обход по ссылкам
 * от главной страницы. Так сбор переживает и устаревшую карту, и её отсутствие.
 *
 * Кэширование двухуровневое: условный запрос по `ETag` и сверка SHA-256
 * содержимого. Второй уровень нужен на случай, когда `ETag` не поддерживается
 * либо меняется при неизменном тексте (SC-008).
 */
final class DocsCrawler
{
    /** Предохранитель от неограниченного обхода при неожиданной структуре сайта. */
    private const MAX_PAGES = 600;

    /**
     * Область обхода — концептуальная документация под `/pages/`.
     *
     * Ограничение обязательно. Без него обход уходит в раздел `/api/` — это
     * отдельный справочник на тысячи страниц, который спецификация исключила
     * из области работ явным решением. На первом прогоне очередь разрослась
     * до 3000+ адресов и упёрлась в предохранитель, потратив пять минут
     * в основном на чужой раздел.
     */
    private const SCOPE_PREFIX = '/pages/';

    public function __construct(
        private readonly HttpFetcher $fetcher,
        private readonly ContentExtractor $extractor = new ContentExtractor(),
    ) {
    }

    /**
     * @return array{fetched: int, unchanged: int, failed: int, skipped: int,
     *               visited: int, error: string|null}
     */
    public function crawl(PDO $pdo, string $baseUrl, Reporter $out, bool $force = false): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $host = parse_url($baseUrl, PHP_URL_HOST);

        if (!is_string($host)) {
            return $this->failure('некорректный базовый URL');
        }

        $policy = $this->loadRobots($baseUrl, $out);
        $cached = $this->cachedPages($pdo);

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var list<string> $queue */
        $queue = [];

        foreach ($this->seeds($baseUrl, $out) as $url) {
            if (!isset($seen[$url])) {
                $seen[$url] = true;
                $queue[] = $url;
            }
        }

        if ($queue === []) {
            return $this->failure('не удалось получить ни одной точки входа');
        }

        $insert = $pdo->prepare(
            'INSERT INTO doc_page
                (url, section, title, content, fetched_at, etag, content_sha256, version_mentions,
                 links, source_type, source_ref, source_revision)
             VALUES (:url, :section, :title, :content, :at, :etag, :sha, :versions,
                     :links, \'web\', :url, NULL)
             ON CONFLICT (url) DO UPDATE SET
                section = excluded.section, title = excluded.title, content = excluded.content,
                fetched_at = excluded.fetched_at, etag = excluded.etag,
                content_sha256 = excluded.content_sha256,
                version_mentions = excluded.version_mentions, links = excluded.links,
                source_type = excluded.source_type, source_ref = excluded.source_ref,
                source_revision = excluded.source_revision'
        );

        $fetched = 0;
        $unchanged = 0;
        $failed = 0;
        $skipped = 0;
        $visited = 0;

        while ($queue !== [] && $visited < self::MAX_PAGES) {
            $url = array_shift($queue);
            $path = parse_url($url, PHP_URL_PATH);

            if (is_string($path) && !$policy->allows($path)) {
                // Запрет из robots.txt — соблюдение правил, а не ошибка.
                $skipped++;
                continue;
            }

            $visited++;
            $known = $cached[$url] ?? null;
            // После перехода от плоского списка версий к структурированным
            // примечаниям старый кэш надо извлечь заново даже при том же ETag.
            $canUseCache = !$force && $known !== null && !$known['needsReextract'];
            $response = $this->fetcher->get($url, $canUseCache ? $known['etag'] : null);

            if ($response['status'] === 304) {
                // Тело не пришло, но обход продолжать надо: ссылки берём
                // из сохранённых при прошлом сборе. Без этого повторный
                // запуск обрывался бы на первой закэшированной странице.
                foreach ($known['links'] ?? [] as $link) {
                    if (!isset($seen[$link])) {
                        $seen[$link] = true;
                        $queue[] = $link;
                    }
                }

                $unchanged++;
                continue;
            }

            if ($response['status'] !== 200 || $response['body'] === null) {
                $failed++;
                continue;
            }

            $links = $this->linksFrom($response['body'], $url, $host);

            foreach ($links as $link) {
                if (!isset($seen[$link])) {
                    $seen[$link] = true;
                    $queue[] = $link;
                }
            }

            $sha = hash('sha256', $response['body']);

            if ($canUseCache && $known['sha256'] === $sha) {
                $unchanged++;
                continue;
            }

            $parsed = $this->extractor->extract($response['body']);

            // Страницы без содержательного текста (редиректы, заглушки)
            // в индекс не попадают, но и ошибкой не считаются.
            if (mb_strlen($parsed['content']) < 200) {
                continue;
            }

            $insert->execute([
                ':url' => $url,
                ':section' => $this->sectionOf($url),
                ':title' => $parsed['title'],
                ':content' => $parsed['content'],
                ':at' => gmdate('c'),
                ':etag' => $response['etag'],
                ':sha' => $sha,
                // JSON хранит не только число, но и субъект, заголовок и
                // утверждение. schema служит маркером миграции кэша.
                ':versions' => json_encode(
                    ['schema' => VersionNoteParser::FORMAT_SCHEMA, 'notes' => $parsed['versionNotes']],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                ),
                ':links' => $links === [] ? null : implode("\n", $links),
            ]);

            $fetched++;

            if ($visited % 10 === 0) {
                $out->progress(sprintf('  обойдено %d, в очереди %d', $visited, count($queue)));
            }
        }

        $out->progressDone();

        if ($queue !== []) {
            // Молчаливое усечение читалось бы как «обошли всё» (Принцип IV).
            $out->line(sprintf(
                'ВНИМАНИЕ: достигнут предел в %d страниц, в очереди осталось %d',
                self::MAX_PAGES,
                count($queue),
            ));
        }

        return [
            'fetched' => $fetched,
            'unchanged' => $unchanged,
            'failed' => $failed,
            'skipped' => $skipped,
            'visited' => $visited,
            'error' => null,
        ];
    }

    /**
     * Точки входа: главная страница плюс нормализованные адреса карты сайта.
     *
     * @return list<string>
     */
    private function seeds(string $baseUrl, Reporter $out): array
    {
        $seeds = [$baseUrl . '/'];
        $sitemap = $this->loadSitemap($baseUrl);

        if ($sitemap === null) {
            $out->line('sitemap.xml недоступен — обход только по ссылкам');

            return $seeds;
        }

        // В актуальной карте пути канонические (`/framework/x.html`), но
        // фактические страницы отдаются под `/pages/framework/x.html`.
        // Нормализация превращает все 221 адрес в рабочие точки входа.
        $usable = [];
        foreach ($sitemap as $url) {
            $normalized = self::normalizeSitemapUrl($baseUrl, $url);

            if ($normalized !== null) {
                $usable[] = $normalized;
            }
        }
        $usable = array_values(array_unique($usable));

        $out->line(sprintf(
            'Карта сайта: %d адресов, пригодны как затравка %d (остальные вне области %s)',
            count($sitemap),
            count($usable),
            self::SCOPE_PREFIX,
        ));

        return array_merge($seeds, $usable);
    }

    /**
     * Преобразует канонический URL sitemap в фактический URL страницы.
     * Чужие хосты, служебный поиск и справочник `/api/` не входят в область
     * концептуальной документации: факты API берутся из локального ядра.
     */
    public static function normalizeSitemapUrl(string $baseUrl, string $url): ?string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        $host = parse_url($url, PHP_URL_HOST);
        $path = (string)parse_url($url, PHP_URL_PATH);

        if (
            !is_string($baseHost) || $host !== $baseHost || $path === ''
            || str_starts_with($path, '/_search/') || str_starts_with($path, '/api/')
        ) {
            return null;
        }

        if (!str_ends_with($path, '.html')) {
            return null;
        }

        if (!str_starts_with($path, self::SCOPE_PREFIX)) {
            $path = self::SCOPE_PREFIX . ltrim($path, '/');
        }

        return $baseUrl . $path;
    }

    /** @return list<string>|null */
    private function loadSitemap(string $baseUrl): ?array
    {
        $response = $this->fetcher->get($baseUrl . '/sitemap.xml');

        if ($response['status'] !== 200 || $response['body'] === null) {
            return null;
        }

        if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $response['body'], $matches) === false) {
            return null;
        }

        return array_values(array_unique($matches[1]));
    }

    /**
     * Ссылки на HTML-страницы того же хоста.
     *
     * @return list<string>
     */
    private function linksFrom(string $html, string $currentUrl, string $host): array
    {
        if (preg_match_all('/href\s*=\s*"([^"]+)"/i', $html, $matches) === false) {
            return [];
        }

        // Элемент <base href> меняет точку отсчёта для ВСЕХ относительных
        // ссылок страницы. На целевом сайте он задан как `../../`, поэтому
        // ссылка `pages/framework/x.html` со страницы `/pages/framework/y.html`
        // ведёт на `/pages/framework/x.html`, а не на вложенный путь.
        // Игнорирование <base> давало 38 несуществующих адресов из 43.
        $base = $this->resolveBase($html, $currentUrl);

        $hrefs = $matches[1];
        $result = [];

        foreach ($hrefs as $href) {
            $href = trim($href);

            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')) {
                continue;
            }

            $absolute = $this->absolutize($href, $base);

            if ($absolute === null) {
                continue;
            }

            if (parse_url($absolute, PHP_URL_HOST) !== $host) {
                continue;
            }

            $path = (string)parse_url($absolute, PHP_URL_PATH);

            if (!str_starts_with($path, self::SCOPE_PREFIX)) {
                continue;
            }

            if (!str_ends_with($path, '.html') && !str_ends_with($path, '/')) {
                continue;
            }

            // Якорь отбрасывается: он ведёт на ту же страницу.
            $result[] = strtok($absolute, '#') ?: $absolute;
        }

        return array_values(array_unique($result));
    }

    /**
     * Эффективная база для относительных ссылок: значение <base href>,
     * разрешённое относительно адреса самой страницы. При отсутствии
     * элемента базой служит адрес страницы.
     */
    private function resolveBase(string $html, string $currentUrl): string
    {
        if (preg_match('/<base\s[^>]*href\s*=\s*"([^"]+)"/i', $html, $m) !== 1) {
            return $currentUrl;
        }

        $resolved = $this->absolutize(trim($m[1]), $currentUrl);

        if ($resolved === null) {
            return $currentUrl;
        }

        // База обязана оканчиваться слэшем, иначе последний сегмент
        // будет отброшен как имя файла.
        return str_ends_with($resolved, '/') ? $resolved : $resolved . '/';
    }

    private function absolutize(string $href, string $base): ?string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }

        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);

        if (!is_string($scheme) || !is_string($host)) {
            return null;
        }

        $origin = $scheme . '://' . $host;

        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }

        $basePath = (string)parse_url($base, PHP_URL_PATH);
        $dir = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';

        $combined = $origin . $dir . $href;

        // Схлопывание ./ и ../ — иначе один и тот же документ попадёт
        // в очередь под несколькими адресами.
        $combined = preg_replace('#/\./#', '/', $combined) ?? $combined;

        while (preg_match('#/[^/]+/\.\./#', $combined) === 1) {
            $combined = preg_replace('#/[^/]+/\.\./#', '/', $combined) ?? $combined;
        }

        return $combined;
    }

    private function loadRobots(string $baseUrl, Reporter $out): RobotsPolicy
    {
        $response = $this->fetcher->get($baseUrl . '/robots.txt');

        if ($response['status'] !== 200 || $response['body'] === null) {
            $out->line('robots.txt недоступен — ограничений нет');

            return RobotsPolicy::permissive();
        }

        $policy = RobotsPolicy::parse($response['body']);
        $disallowed = $policy->disallowed();

        $out->line(
            $disallowed === []
                ? 'robots.txt: ограничений нет'
                : 'robots.txt запрещает: ' . implode(', ', $disallowed)
        );

        return $policy;
    }

    /**
     * Раздел документации.
     *
     * Реальные адреса имеют вид `/pages/<раздел>/<страница>.html`, поэтому
     * служебный сегмент `pages` пропускается: разделом является следующий.
     */
    private function sectionOf(string $url): string
    {
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        $parts = array_values(array_filter(explode('/', $path)));

        if (($parts[0] ?? '') === 'pages') {
            array_shift($parts);
        }

        return count($parts) > 1 ? $parts[0] : 'root';
    }

    /**
     * @return array<string, array{
     *   etag: string|null,
     *   sha256: string,
     *   links: list<string>,
     *   needsReextract: bool
     * }>
     */
    private function cachedPages(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT url, etag, content_sha256, links, version_mentions FROM doc_page'
        );

        if ($stmt === false) {
            return [];
        }

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            /** @var array{
             *   url: string,
             *   etag: string|null,
             *   content_sha256: string,
             *   links: string|null,
             *   version_mentions: string|null
             * } $row
             */
            $links = $row['links'] === null || $row['links'] === ''
                ? []
                : array_values(array_filter(explode("\n", $row['links'])));

            $versionData = json_decode($row['version_mentions'] ?? '', true);
            $isStructured = is_array($versionData)
                && ($versionData['schema'] ?? null) === VersionNoteParser::FORMAT_SCHEMA
                && is_array($versionData['notes'] ?? null);

            $result[$row['url']] = [
                'etag' => $row['etag'],
                'sha256' => $row['content_sha256'],
                'links' => $links,
                'needsReextract' => !$isStructured,
            ];
        }

        return $result;
    }

    /**
     * @return array{fetched: int, unchanged: int, failed: int, skipped: int,
     *               visited: int, error: string}
     */
    private function failure(string $message): array
    {
        return ['fetched' => 0, 'unchanged' => 0, 'failed' => 0, 'skipped' => 0,
                'visited' => 0, 'error' => $message];
    }
}
