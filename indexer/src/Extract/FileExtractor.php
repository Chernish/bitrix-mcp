<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

use BitrixMcp\Indexer\Extract\Component\ComponentExtractor;
use BitrixMcp\Indexer\Extract\Event\EventVisitor;
use PhpParser\Error as ParserError;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Разбор одного файла и извлечение из него всех сущностей.
 *
 * Ошибка разбора возвращается вызывающей стороне, а не бросается наружу:
 * один битый файл из 67 835 не имеет права уронить проход (Принцип V).
 *
 * Относительный путь нужен не для диагностики: по нему определяется, является
 * ли файл объявлением компонента. Идентичность компонента задаётся его
 * расположением (`components/<ns>/<name>/`), а не содержимым.
 */
final class FileExtractor
{
    private readonly Parser $parser;
    private readonly NodeTraverser $traverser;
    private readonly SymbolVisitor $symbolVisitor;
    private readonly AliasVisitor $aliasVisitor;
    private readonly EventVisitor $eventVisitor;
    private readonly FunctionVisitor $functionVisitor;
    private readonly UsageVisitor $usageVisitor;
    private readonly RestVisitor $restVisitor;
    private readonly ModuleDependencyVisitor $dependencyVisitor;
    private readonly ComponentExtractor $components;

    public function __construct(?Parser $parser = null)
    {
        $this->parser = $parser ?? (new ParserFactory())->createForNewestSupportedVersion();

        $this->symbolVisitor = new SymbolVisitor();
        $this->aliasVisitor = new AliasVisitor();
        $this->eventVisitor = new EventVisitor();
        $this->functionVisitor = new FunctionVisitor();
        $this->usageVisitor = new UsageVisitor();
        $this->restVisitor = new RestVisitor();
        $this->dependencyVisitor = new ModuleDependencyVisitor();
        $this->components = new ComponentExtractor();

        $this->traverser = new NodeTraverser();
        // NameResolver обязан идти первым: остальные визиторы полагаются
        // на разрешённые полные имена.
        $this->traverser->addVisitor(new NameResolver());
        $this->traverser->addVisitor($this->symbolVisitor);
        $this->traverser->addVisitor($this->aliasVisitor);
        $this->traverser->addVisitor($this->eventVisitor);
        $this->traverser->addVisitor($this->functionVisitor);
        $this->traverser->addVisitor($this->usageVisitor);
        $this->traverser->addVisitor($this->restVisitor);
        $this->traverser->addVisitor($this->dependencyVisitor);
    }

    /**
     * @return array{0: FileExtraction|null, 1: string|null, 2: int|null}
     *         извлечённое, сообщение об ошибке, строка ошибки
     */
    public function extract(string $code, string $relPath = ''): array
    {
        try {
            $stmts = $this->parser->parse($code);
        } catch (ParserError $e) {
            return [null, $e->getRawMessage(), $e->getStartLine() > 0 ? $e->getStartLine() : null];
        } catch (\Throwable $e) {
            return [null, $e->getMessage(), null];
        }

        if ($stmts === null) {
            return [null, 'парсер вернул пустой результат', null];
        }

        $this->traverser->traverse($stmts);

        return [
            new FileExtraction(
                symbols: $this->symbolVisitor->symbols(),
                aliases: $this->aliasVisitor->aliases(),
                events: $this->eventVisitor->events(),
                functions: $this->functionVisitor->functions(),
                usages: $this->usageVisitor->usages(),
                restMethods: $this->restVisitor->methods(),
                dependencies: $this->dependencyVisitor->modules(),
                component: $relPath === '' ? null : $this->components->extract($stmts, $relPath),
            ),
            null,
            null,
        ];
    }
}
