<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract\Component;

/**
 * Компонент Битрикса и его параметры.
 *
 * Извлечение ЗАВЕДОМО частичное, и это не дефект реализации, а следствие
 * Принципа VI. Файл `.parameters.php` — исполняемый скрипт, а не манифест:
 * в реальных компонентах он делает запросы к базе и вычисляет значения через
 * `GetMessage()` и `COption::GetOptionString()`. Выполнить его нельзя, значит
 * часть значений принципиально недоступна (R-004).
 *
 * `unresolvedCount` делает эту неполноту видимой вместо того, чтобы выдавать
 * вычисляемое значение за отсутствующее.
 */
final readonly class ExtractedComponent
{
    /** @param list<ExtractedComponentParam> $params */
    public function __construct(
        public string $namespace,
        public string $name,
        public string $fullName,
        public ?string $title,
        public ?string $description,
        public array $params,
        /**
         * false — файл существует, но `$arComponentParameters` не задан
         * литеральным массивом и статически не извлекается.
         * Компонент всё равно регистрируется: молчаливая потеря запрещена
         * Принципом III, а «нет параметров» и «параметры не извлеклись» —
         * разные состояния.
         */
        public bool $paramsResolved = true,
    ) {
    }

    public function unresolvedCount(): int
    {
        return count(array_filter($this->params, static fn($p) => !$p->isDefaultResolved));
    }
}
