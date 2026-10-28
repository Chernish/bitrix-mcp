<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Unit;

use BitrixMcp\Indexer\Extract\Event\ExtractedEvent;
use BitrixMcp\Indexer\Extract\FileExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Извлечение событий обоих поколений API.
 *
 * Имена событий — вторая по частоте категория галлюцинаций после имён методов,
 * а найти их вручную в 67 835 файлах дорого.
 */
final class EventExtractionTest extends TestCase
{
    private FileExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FileExtractor();
    }

    /** @return list<ExtractedEvent> */
    private function eventsOf(string $code): array
    {
        [$extraction] = $this->extractor->extract($code);
        self::assertNotNull($extraction);

        return $extraction->events;
    }

    public function testLegacyGetModuleEventsIsSend(): void
    {
        $events = $this->eventsOf(<<<'PHP'
        <?php
        foreach (GetModuleEvents("vote", "onBeforeVoteDelete", true) as $handler) {
            ExecuteModuleEventEx($handler, [$id]);
        }
        PHP);

        self::assertCount(1, $events);
        self::assertSame('vote', $events[0]->moduleCode);
        self::assertSame('onBeforeVoteDelete', $events[0]->name);
        self::assertSame(ExtractedEvent::KIND_SEND, $events[0]->kind);
        self::assertSame('GetModuleEvents', $events[0]->callStyle);
    }

    public function testD7NewEventIsSend(): void
    {
        $events = $this->eventsOf(<<<'PHP'
        <?php
        namespace Bitrix\Sale;

        use Bitrix\Main\Event;

        class Discount
        {
            public function run(): void
            {
                $event = new Event('sale', 'OnSaleDiscountPresetBuildList', ['ID' => 1]);
                $event->send();
            }
        }
        PHP);

        self::assertCount(1, $events);
        self::assertSame('sale', $events[0]->moduleCode);
        self::assertSame('OnSaleDiscountPresetBuildList', $events[0]->name);
        self::assertSame(ExtractedEvent::KIND_SEND, $events[0]->kind);
        self::assertSame('new Event', $events[0]->callStyle);
        self::assertStringContainsString('ID', (string)$events[0]->paramsRepr);
    }

    public function testHandlerRegistrationIsSeparateKind(): void
    {
        $events = $this->eventsOf(<<<'PHP'
        <?php
        AddEventHandler("main", "OnBeforeUserAdd", ["CMyClass", "handler"]);
        RegisterModuleDependences("iblock", "OnAfterIBlockElementAdd", "crm", "CCrmHandler", "run");
        PHP);

        self::assertCount(2, $events);

        foreach ($events as $event) {
            self::assertSame(
                ExtractedEvent::KIND_REGISTER,
                $event->kind,
                'Подписка на событие не должна попадать в список бросаемых событий',
            );
        }

        self::assertSame('main', $events[0]->moduleCode);
        self::assertSame('OnBeforeUserAdd', $events[0]->name);
        self::assertSame('iblock', $events[1]->moduleCode);
    }

    public function testEventManagerRegistrationIsRecognised(): void
    {
        $events = $this->eventsOf(<<<'PHP'
        <?php
        use Bitrix\Main\EventManager;

        EventManager::getInstance()->addEventHandler('sale', 'onSaleOrderSaved', $callback);
        PHP);

        self::assertCount(1, $events);
        self::assertSame('EventManager', $events[0]->callStyle);
        self::assertSame(ExtractedEvent::KIND_REGISTER, $events[0]->kind);
        self::assertSame('onSaleOrderSaved', $events[0]->name);
    }

    public function testDynamicEventNameIsNotGuessed(): void
    {
        $events = $this->eventsOf(<<<'PHP'
        <?php
        $name = 'On' . $suffix;
        AddEventHandler("main", $name, $callback);
        GetModuleEvents($module, "OnSomething");
        PHP);

        self::assertSame(
            [],
            $events,
            'Динамически собранное имя достроить нельзя, а угадывать запрещено (Принцип III)',
        );
    }

    public function testLineNumbersAreRecorded(): void
    {
        $events = $this->eventsOf("<?php\n\n\nAddEventHandler(\"main\", \"OnFoo\", \$cb);\n");

        self::assertCount(1, $events);
        self::assertSame(4, $events[0]->line);
    }
}
