<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract\Event;

/**
 * Событие Битрикса: либо точка вызова, либо регистрация обработчика.
 *
 * Разделение по `kind` отвечает на два разных вопроса агента: «какие события
 * бросает этот модуль» и «кто на них уже подписан». Склеивание их в один список
 * сделало бы выдачу бесполезной для обоих вопросов.
 */
final readonly class ExtractedEvent
{
    public const KIND_SEND = 'send';
    public const KIND_REGISTER = 'register';

    public function __construct(
        /** Код модуля, которому принадлежит событие, — из первого аргумента вызова. */
        public string $moduleCode,
        public string $name,
        /** send | register */
        public string $kind,
        /** GetModuleEvents | ExecuteModuleEventEx | EventManager | new Event | addEventHandler */
        public string $callStyle,
        public int $line,
        /** Текстовое представление передаваемых параметров, если извлекаемо. */
        public ?string $paramsRepr,
    ) {
    }
}
