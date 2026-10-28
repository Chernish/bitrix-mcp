<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Unit;

use BitrixMcp\Indexer\Docs\ContentExtractor;
use BitrixMcp\Indexer\Docs\DocsCrawler;
use BitrixMcp\Indexer\Docs\DocsRepositorySnapshot;
use BitrixMcp\Indexer\Docs\GitDocsImporter;
use BitrixMcp\Indexer\Docs\MarkdownContentExtractor;
use BitrixMcp\Indexer\Docs\RobotsPolicy;
use BitrixMcp\Indexer\Storage\Schema;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Разбор служебных артефактов сбора документации.
 *
 * Сетевую часть здесь не трогаем: тест не должен зависеть от доступности
 * чужого сайта. Проверяется то, что при сборе пошло не так на практике.
 */
final class DocsCrawlerTest extends TestCase
{
    public function testRobotsDisallowIsRespected(): void
    {
        $policy = RobotsPolicy::parse(<<<'TXT'
        User-agent: *
        Disallow: /_search/

        Sitemap: https://example.org/sitemap.xml
        TXT);

        self::assertTrue($policy->loaded);
        self::assertSame(['/_search/'], $policy->disallowed());
        self::assertFalse($policy->allows('/_search/query'));
        self::assertTrue($policy->allows('/pages/framework/architecture.html'));
    }

    public function testRulesForOtherAgentsAreIgnored(): void
    {
        $policy = RobotsPolicy::parse(<<<'TXT'
        User-agent: BadBot
        Disallow: /

        User-agent: *
        Disallow: /private/
        TXT);

        self::assertTrue(
            $policy->allows('/pages/'),
            'Запрет для чужого агента применён к нам — обход остановился бы без причины',
        );
        self::assertFalse($policy->allows('/private/x'));
    }

    public function testMissingRobotsMeansNoRestrictions(): void
    {
        $policy = RobotsPolicy::permissive();

        self::assertFalse($policy->loaded);
        self::assertTrue($policy->allows('/anything'));
    }

    public function testContentExtractionStripsChromeAndKeepsText(): void
    {
        $extractor = new ContentExtractor();

        $result = $extractor->extract(<<<'HTML'
        <html><head><title>Заголовок вкладки</title></head>
        <body>
          <nav>меню навигации</nav>
          <main>
            <h1>Кеширование</h1>
            <p>Управляемый кеш появился начиная с версии 20.100.0 и работает так.</p>
            <script>console.log('шум')</script>
          </main>
          <footer>подвал</footer>
        </body></html>
        HTML);

        self::assertSame('Кеширование', $result['title']);
        self::assertStringContainsString('Управляемый кеш', $result['content']);
        self::assertStringNotContainsString('меню навигации', $result['content']);
        self::assertStringNotContainsString('подвал', $result['content']);
        self::assertStringNotContainsString('console.log', $result['content']);
    }

    public function testDiplodocChromeAndHiddenHeadingCopyAreRemoved(): void
    {
        $extractor = new ContentExtractor();

        $result = $extractor->extract(<<<'HTML'
        <html><body><main>
          <div class="dc-doc-page__content-mini-toc">Пункт оглавления</div>
          <div class="dc-doc-page__body yfm">
            <h2><a><span data-no-index="true">HTTP-клиент</span></a>HTTP-клиент</h2>
            <p>С версии Главного модуля 23.0.0 доступен PSR-18.</p>
          </div>
        </main></body></html>
        HTML);

        self::assertStringStartsWith('HTTP-клиент', $result['content']);
        self::assertStringNotContainsString('HTTP-клиентHTTP-клиент', $result['content']);
        self::assertStringNotContainsString('Пункт оглавления', $result['content']);
        self::assertSame('HTTP-клиент', $result['versionNotes'][0]['heading']);
    }

    public function testVersionMentionsAreCollectedFromProse(): void
    {
        $extractor = new ContentExtractor();

        $result = $extractor->extract(
            '<html><body><main><p>Доступно с 24.0 и расширено начиная с версии 25.100.300.</p></main></body></html>'
        );

        self::assertSame(['24.0', '25.100.300'], $result['versionMentions']);
    }

    public function testVersionNotesKeepModuleAndContext(): void
    {
        $extractor = new ContentExtractor();

        $result = $extractor->extract(<<<'HTML'
        <html><body><main>
          <h2>HTTP-клиент</h2>
          <p>С версии Главного модуля 23.0.0 HttpClient поддерживает PSR-18.</p>
          <p>С версии PHP 8.0 можно подключить библиотеку cURL.</p>
          <p>Событие доступно с версии модуля UI 22.300.0.</p>
        </main></body></html>
        HTML);

        self::assertCount(3, $result['versionNotes']);
        self::assertSame('main', $result['versionNotes'][0]['module']);
        self::assertSame('module', $result['versionNotes'][0]['subject_kind']);
        self::assertSame('HTTP-клиент', $result['versionNotes'][0]['heading']);
        self::assertSame('php', $result['versionNotes'][1]['subject_kind']);
        self::assertArrayNotHasKey('module', $result['versionNotes'][1]);
        self::assertSame('ui', $result['versionNotes'][2]['module']);
    }

    public function testVersionSubjectDoesNotLeakFromAnotherSentenceInLargeBlock(): void
    {
        $extractor = new ContentExtractor();

        $result = $extractor->extract(<<<'HTML'
        <html><body><main><ul><li>
          main.bundle содержит настройки сборки.
          // Доступно с версии 3.0.0.
          С версии Главного модуля 24.100.0 доступен новый API.
        </li></ul></main></body></html>
        HTML);

        self::assertCount(2, $result['versionNotes']);
        self::assertSame('unknown', $result['versionNotes'][0]['subject_kind']);
        self::assertArrayNotHasKey('module', $result['versionNotes'][0]);
        self::assertSame('main', $result['versionNotes'][1]['module']);
        self::assertStringStartsWith('// Доступно', $result['versionNotes'][0]['statement']);
    }

    public function testSitemapUrlsReceiveActualPagesPrefix(): void
    {
        self::assertSame(
            'https://docs.1c-bitrix.ru/pages/framework/routing.html',
            DocsCrawler::normalizeSitemapUrl(
                'https://docs.1c-bitrix.ru',
                'https://docs.1c-bitrix.ru/framework/routing.html',
            ),
        );
        self::assertSame(
            'https://docs.1c-bitrix.ru/pages/ui/a11y/focus-monitor.html',
            DocsCrawler::normalizeSitemapUrl(
                'https://docs.1c-bitrix.ru',
                'https://docs.1c-bitrix.ru/ui/a11y/focus-monitor.html',
            ),
        );
        self::assertNull(DocsCrawler::normalizeSitemapUrl(
            'https://docs.1c-bitrix.ru',
            'https://docs.1c-bitrix.ru/api/classes/Bitrix-Main-Application.html',
        ));
    }

    public function testTitleFallsBackToTitleTag(): void
    {
        $extractor = new ContentExtractor();

        $result = $extractor->extract(
            '<html><head><title>Только заголовок вкладки</title></head><body><main><p>текст</p></main></body></html>'
        );

        self::assertSame('Только заголовок вкладки', $result['title']);
    }

    public function testMarkdownKeepsVersionNoteAndHeadingContext(): void
    {
        $result = (new MarkdownContentExtractor())->extract(<<<'MD'
        ---
        title: Защищенные cookie
        ---

        {% note info "" %}

        С версии 20.5.400 Главного модуля доступны защищенные cookie `CryptoCookie`.

        {% endnote %}

        ## Использование

        Основной текст статьи.
        MD);

        self::assertSame('Защищенные cookie', $result['title']);
        self::assertStringNotContainsString('{% note', $result['content']);
        self::assertSame('20.5.400', $result['versionNotes'][0]['version']);
        self::assertSame('main', $result['versionNotes'][0]['module']);
    }

    public function testMarkdownVersionMayBeWrappedInCodeTicks(): void
    {
        $result = (new MarkdownContentExtractor())->extract(
            'Функционал доступен с версии `25.700.0` главного модуля.'
        );

        self::assertSame('25.700.0', $result['versionNotes'][0]['version']);
        self::assertSame('main', $result['versionNotes'][0]['module']);
    }

    public function testGitImporterUsesCanonicalUrlAndRecordsRevision(): void
    {
        $root = sys_get_temp_dir() . '/bitrix-docs-import-' . bin2hex(random_bytes(6));
        mkdir($root . '/pages/security', 0o775, true);
        file_put_contents($root . '/pages/security/cookies.md', <<<'MD'
        ---
        title: Cookie
        ---

        С версии 20.5.400 Главного модуля доступны защищенные cookie.
        MD);

        try {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            Schema::create($pdo);
            $pdo->exec(
                "INSERT INTO doc_page
                    (url, section, content, fetched_at, content_sha256)
                 VALUES ('https://docs.1c-bitrix.ru/', 'root', 'старый web-кэш', '2025-01-01', 'old')"
            );
            $result = (new GitDocsImporter())->import(
                $pdo,
                new DocsRepositorySnapshot($root, str_repeat('a', 40), 'fixture'),
            );

            self::assertSame(1, $result['pages']);
            self::assertSame(1, $result['removed']);
            $page = $pdo->query(
                'SELECT url, source_type, source_ref, source_revision FROM doc_page'
            )?->fetch(PDO::FETCH_ASSOC);
            self::assertSame('https://docs.1c-bitrix.ru/pages/security/cookies.html', $page['url']);
            self::assertSame('git', $page['source_type']);
            self::assertSame('pages/security/cookies.md', $page['source_ref']);
            self::assertSame(str_repeat('a', 40), $page['source_revision']);

            $second = (new GitDocsImporter())->import(
                $pdo,
                new DocsRepositorySnapshot($root, str_repeat('a', 40), 'fixture'),
            );
            self::assertSame(0, $second['changed']);
            self::assertSame(1, $second['unchanged']);
        } finally {
            unlink($root . '/pages/security/cookies.md');
            rmdir($root . '/pages/security');
            rmdir($root . '/pages');
            rmdir($root);
        }
    }
}
