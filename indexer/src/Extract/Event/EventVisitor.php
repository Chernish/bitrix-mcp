<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract\Event;

use BitrixMcp\Indexer\Extract\SignatureRenderer;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Извлечение событий обоих поколений API.
 *
 * Во всех поддерживаемых формах модуль и имя события заданы строковыми
 * литералами в фиксированных позициях аргументов, что даёт надёжное извлечение
 * через AST без единой текстовой эвристики (R-005).
 *
 * Старое API:
 *   GetModuleEvents("vote", "onBeforeVoteDelete")
 *   ExecuteModuleEventEx($event, [...])
 *   RegisterModuleDependences("main", "OnBeforeUserAdd", "crm", ...)
 *   AddEventHandler("main", "OnBeforeUserAdd", ...)
 *
 * D7:
 *   new Event('sale', 'OnSaleDiscountPresetBuildList')
 *   EventManager::getInstance()->addEventHandler('sale', 'onFoo', ...)
 *
 * Динамически собранные имена событий не извлекаются: достроить их нельзя,
 * а угадывать запрещено (Принцип III).
 */
final class EventVisitor extends NodeVisitorAbstract
{
    /** Вызовы, где (модуль, событие) стоят на позициях 0 и 1 и означают отправку. */
    private const SEND_FUNCTIONS = [
        'getmoduleevents' => 'GetModuleEvents',
    ];

    /** Вызовы, означающие подписку на событие. */
    private const REGISTER_FUNCTIONS = [
        'addeventhandler' => 'AddEventHandler',
        'registermoduledependences' => 'RegisterModuleDependences',
        'registermoduledependencies' => 'RegisterModuleDependences',
    ];

    /** Методы менеджера событий: подписка. */
    private const REGISTER_METHODS = [
        'addeventhandler' => 'EventManager',
        'addeventhandlercompatible' => 'EventManager',
        'registereventhandler' => 'EventManager',
    ];

    /** @var list<ExtractedEvent> */
    private array $events = [];

    public function __construct(
        private readonly SignatureRenderer $renderer = new SignatureRenderer(),
    ) {
    }

    public function beforeTraverse(array $nodes): ?array
    {
        $this->events = [];

        return null;
    }

    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Expr\FuncCall) {
            $this->handleFuncCall($node);

            return null;
        }

        if ($node instanceof Node\Expr\New_) {
            $this->handleNewEvent($node);

            return null;
        }

        if ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) {
            $this->handleMethodCall($node);
        }

        return null;
    }

    /** @return list<ExtractedEvent> */
    public function events(): array
    {
        return $this->events;
    }

    private function handleFuncCall(Node\Expr\FuncCall $call): void
    {
        if (!$call->name instanceof Node\Name) {
            return;
        }

        $lower = $call->name->toLowerString();

        $style = self::SEND_FUNCTIONS[$lower] ?? null;
        $kind = ExtractedEvent::KIND_SEND;

        if ($style === null) {
            $style = self::REGISTER_FUNCTIONS[$lower] ?? null;
            $kind = ExtractedEvent::KIND_REGISTER;
        }

        if ($style === null) {
            return;
        }

        $this->record($call->args, $kind, $style, $call->getStartLine());
    }

    /**
     * D7: `new Event('module', 'EventName', $params)`.
     *
     * Имя класса проверяется по короткому имени, потому что после NameResolver
     * оно может быть как \Bitrix\Main\Event, так и локальным подклассом.
     */
    private function handleNewEvent(Node\Expr\New_ $new): void
    {
        if (!$new->class instanceof Node\Name) {
            return;
        }

        $parts = $new->class->getParts();
        $short = strtolower((string)($parts[count($parts) - 1] ?? ''));

        if ($short !== 'event') {
            return;
        }

        $this->record($new->args, ExtractedEvent::KIND_SEND, 'new Event', $new->getStartLine());
    }

    private function handleMethodCall(Node\Expr\MethodCall|Node\Expr\StaticCall $call): void
    {
        if (!$call->name instanceof Node\Identifier) {
            return;
        }

        $style = self::REGISTER_METHODS[$call->name->toLowerString()] ?? null;

        if ($style === null) {
            return;
        }

        $this->record($call->args, ExtractedEvent::KIND_REGISTER, $style, $call->getStartLine());
    }

    /**
     * @param array<Node\Arg|Node\VariadicPlaceholder> $args
     */
    private function record(array $args, string $kind, string $style, int $line): void
    {
        $module = $this->stringArg($args, 0);
        $name = $this->stringArg($args, 1);

        if ($module === null || $name === null) {
            return;
        }

        $params = null;
        $third = $args[2] ?? null;

        if ($third instanceof Node\Arg) {
            $rendered = $this->renderer->renderExpression($third->value);
            // Длинные выражения агенту бесполезны и раздувают индекс.
            $params = mb_strlen($rendered) <= 160 ? $rendered : null;
        }

        $this->events[] = new ExtractedEvent(
            moduleCode: $module,
            name: $name,
            kind: $kind,
            callStyle: $style,
            line: $line,
            paramsRepr: $params,
        );
    }

    /** @param array<Node\Arg|Node\VariadicPlaceholder> $args */
    private function stringArg(array $args, int $index): ?string
    {
        $arg = $args[$index] ?? null;

        return $arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_
            ? $arg->value->value
            : null;
    }
}
