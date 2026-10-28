<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

use BitrixMcp\Indexer\Extract\Orm\ExtractedOrmEntity;

final readonly class ExtractedSymbol
{
    /**
     * @param list<string> $interfaces полные имена реализуемых интерфейсов
     * @param list<ExtractedMethod> $methods
     * @param list<ExtractedConstant> $constants
     */
    public function __construct(
        public string $fqn,
        public string $shortName,
        public string $kind,
        public ?string $parentFqn,
        public array $interfaces,
        public bool $isAbstract,
        public bool $isFinal,
        /** ФАКТ: прочитан из @deprecated в PHPDoc */
        public bool $isDeprecated,
        public ?string $deprecatedNote,
        /** ЭВРИСТИКА: выведен из формы имени, см. ApiStyle */
        public ApiStyle $apiStyle,
        public ?string $docComment,
        public int $line,
        public array $methods,
        public array $constants,
        /** Заполнено, если класс объявляет getMap() — то есть является ORM-сущностью. */
        public ?ExtractedOrmEntity $orm = null,
    ) {
    }
}
