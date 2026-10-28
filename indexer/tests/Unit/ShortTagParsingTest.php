<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Unit;

use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\InlineHTML;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Регрессия R-001.
 *
 * Этот тест защищает не код проекта, а предположение о поведении окружения:
 * что файл с коротким открывающим тегом действительно разбирается в объявления,
 * а не проглатывается как HTML. Предположение проверяемо только эмпирически,
 * и если оно перестанет выполняться, все прочие тесты останутся зелёными,
 * а индекс станет неполным.
 */
final class ShortTagParsingTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/../fixtures/short-tag-class.php';

    public function testFixtureActuallyUsesShortOpenTag(): void
    {
        $code = file_get_contents(self::FIXTURE);
        self::assertIsString($code);

        self::assertStringStartsWith(
            "<?\n",
            $code,
            'Фикстура потеряла короткий тег и больше ничего не проверяет',
        );
        self::assertStringStartsNotWith('<?php', $code);
    }

    public function testShortTagFileYieldsClassDeclaration(): void
    {
        self::assertTrue(
            filter_var(ini_get('short_open_tag'), FILTER_VALIDATE_BOOLEAN),
            'Тест бессмыслен при выключенном short_open_tag — проверьте phpunit.xml',
        );

        $stmts = $this->parseFixture();

        $classes = array_filter($stmts, static fn($n) => $n instanceof Class_);

        self::assertCount(1, $classes, 'Класс из файла с коротким тегом не найден');
        self::assertSame('CShortTagLegacy', reset($classes)->name?->toString());
    }

    public function testShortTagFileIsNotSwallowedAsInlineHtml(): void
    {
        $stmts = $this->parseFixture();

        $inlineOnly = count($stmts) === 1 && $stmts[0] instanceof InlineHTML;

        self::assertFalse(
            $inlineOnly,
            'Файл разобран как HTML: это тихая потеря данных, описанная в research.md R-001',
        );
    }

    /** @return \PhpParser\Node\Stmt[] */
    private function parseFixture(): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $code = file_get_contents(self::FIXTURE);
        self::assertIsString($code);

        $stmts = $parser->parse($code);
        self::assertNotNull($stmts);

        return $stmts;
    }
}
