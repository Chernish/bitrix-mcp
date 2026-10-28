<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Tests\Unit;

use BitrixMcp\Indexer\Extract\FileExtractor;
use BitrixMcp\Indexer\Extract\Orm\ExtractedOrmEntity;
use PHPUnit\Framework\TestCase;

/**
 * Разбор карт полей ORM.
 *
 * Обязателен по разделу конституции «Качество данных и проверка»: неверно
 * извлечённая карта полей не ломает индексацию, она просто делает схему
 * неверной — отказ тихий и потому дорогой.
 *
 * Проверяются ОБЕ формы объявления: они не сводятся друг к другу, и
 * реализация одной оставила бы вторую невидимой без признаков сбоя (R-003).
 */
final class OrmMapParsingTest extends TestCase
{
    private FileExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FileExtractor();
    }

    private function ormOf(string $code): ExtractedOrmEntity
    {
        [$extraction] = $this->extractor->extract($code);
        self::assertNotNull($extraction);
        self::assertNotSame([], $extraction->symbols);

        $orm = $extraction->symbols[0]->orm;
        self::assertNotNull($orm, 'ORM-сущность не распознана');

        return $orm;
    }

    /** @return array<string, \BitrixMcp\Indexer\Extract\Orm\ExtractedOrmField> */
    private function byName(ExtractedOrmEntity $orm): array
    {
        $map = [];

        foreach ($orm->fields as $field) {
            $map[$field->name] = $field;
        }

        return $map;
    }

    // ── Форма литерального массива ───────────────────────────────────────

    public function testArrayFormExtractsFieldsAndFlags(): void
    {
        $orm = $this->ormOf(<<<'PHP'
        <?php
        namespace Bitrix\Iblock;

        class IblockTable extends \Bitrix\Main\ORM\Data\DataManager
        {
            public static function getTableName(): string
            {
                return 'b_iblock';
            }

            public static function getMap(): array
            {
                return [
                    'ID' => ['data_type' => 'integer', 'primary' => true, 'autocomplete' => true],
                    'CODE' => ['data_type' => 'string', 'required' => true],
                    'SORT' => ['data_type' => 'integer'],
                ];
            }
        }
        PHP);

        self::assertSame('b_iblock', $orm->tableName);
        self::assertSame('literal', $orm->tableNameSource);
        self::assertSame('array', $orm->extractionStyle);
        self::assertTrue($orm->isComplete);
        self::assertNull($orm->incompleteReason);

        $fields = $this->byName($orm);
        self::assertCount(3, $fields);

        self::assertSame('integer', $fields['ID']->dataType);
        self::assertTrue($fields['ID']->isPrimary);
        self::assertTrue($fields['ID']->isAutocomplete);
        self::assertFalse($fields['ID']->isRequired);

        self::assertSame('string', $fields['CODE']->dataType);
        self::assertTrue($fields['CODE']->isRequired);
        self::assertFalse($fields['CODE']->isPrimary);
    }

    public function testClosureDefaultIsMarkedUnresolvedNotDropped(): void
    {
        $orm = $this->ormOf(<<<'PHP'
        <?php
        class T
        {
            public static function getMap(): array
            {
                return [
                    'TIMESTAMP_X' => [
                        'data_type' => 'datetime',
                        'default_value' => function () { return new DateTime(); },
                    ],
                ];
            }
        }
        PHP);

        $field = $this->byName($orm)['TIMESTAMP_X'];

        self::assertFalse(
            $field->isDefaultResolved,
            'Замыкание должно давать признак «не вычислимо», а не молчаливое отсутствие значения',
        );
        self::assertNull($field->defaultRepr);

        self::assertFalse($orm->isComplete, 'Сущность с невычислимым значением не может считаться полной');
        self::assertStringContainsString('замыкани', (string)$orm->incompleteReason);
        self::assertStringContainsString('1 полей', (string)$orm->incompleteReason);
    }

    // ── Fluent-форма ─────────────────────────────────────────────────────

    public function testFluentFormExtractsFieldsAndChainConfiguration(): void
    {
        $orm = $this->ormOf(<<<'PHP'
        <?php
        namespace Bitrix\Vote;

        use Bitrix\Main\ORM\Fields\IntegerField;
        use Bitrix\Main\ORM\Fields\BooleanField;
        use Bitrix\Main\ORM\Fields\StringField;

        class AnswerTable extends \Bitrix\Main\ORM\Data\DataManager
        {
            public static function getMap(): array
            {
                return [
                    (new IntegerField('ID'))
                        ->configurePrimary(true)
                        ->configureAutocomplete(true),
                    (new BooleanField('ACTIVE'))
                        ->configureValues('N', 'Y')
                        ->configureDefaultValue('Y'),
                    (new StringField('COLOR'))->configureSize(6),
                ];
            }
        }
        PHP);

        self::assertSame('fluent', $orm->extractionStyle);
        self::assertTrue($orm->isComplete);

        $fields = $this->byName($orm);
        self::assertCount(3, $fields);

        self::assertSame('integer', $fields['ID']->dataType);
        self::assertTrue($fields['ID']->isPrimary);
        self::assertTrue($fields['ID']->isAutocomplete);

        self::assertSame('boolean', $fields['ACTIVE']->dataType);
        self::assertSame("'Y'", $fields['ACTIVE']->defaultRepr);
        self::assertStringContainsString("'N'", (string)$fields['ACTIVE']->valuesRepr);

        self::assertSame('string', $fields['COLOR']->dataType);
    }

    public function testFluentReferenceExtractsTargetAndJoin(): void
    {
        $orm = $this->ormOf(<<<'PHP'
        <?php
        namespace Bitrix\Vote;

        use Bitrix\Main\ORM\Fields\Relations\Reference;
        use Bitrix\Main\ORM\Query\Join;
        use Bitrix\Main\ORM\Fields\IntegerField;

        class AnswerTable
        {
            public static function getMap(): array
            {
                return [
                    (new IntegerField('IMAGE_ID')),
                    (new Reference('IMAGE', \Bitrix\Main\FileTable::class, Join::on('this.IMAGE_ID', 'ref.ID'))),
                ];
            }
        }
        PHP);

        $image = $this->byName($orm)['IMAGE'];

        self::assertSame('reference', $image->dataType);
        self::assertSame('\Bitrix\Main\FileTable', $image->referenceTargetFqn);
        self::assertStringContainsString('this.IMAGE_ID', (string)$image->joinCondition);
    }

    // ── Краевые случаи ───────────────────────────────────────────────────

    public function testInheritedMapIsMarkedIncomplete(): void
    {
        $orm = $this->ormOf(<<<'PHP'
        <?php
        class Child extends ParentTable
        {
            public static function getMap(): array
            {
                return array_merge(parent::getMap(), [
                    'EXTRA' => ['data_type' => 'string'],
                ]);
            }
        }
        PHP);

        self::assertFalse($orm->isComplete);
        self::assertStringContainsString('наследуется', (string)$orm->incompleteReason);
        self::assertSame('EXTRA', $orm->fields[0]->name);
    }

    public function testDynamicMapIsMarkedUnresolved(): void
    {
        $orm = $this->ormOf(<<<'PHP'
        <?php
        class T
        {
            public static function getMap(): array
            {
                return self::buildMap();
            }
        }
        PHP);

        self::assertSame('unresolved', $orm->extractionStyle);
        self::assertFalse($orm->isComplete);
        self::assertSame([], $orm->fields);
    }

    public function testComputedTableNameIsMarked(): void
    {
        $orm = $this->ormOf(<<<'PHP'
        <?php
        class T
        {
            public static function getTableName(): string
            {
                return self::PREFIX . '_data';
            }

            public static function getMap(): array
            {
                return ['ID' => ['data_type' => 'integer', 'primary' => true]];
            }
        }
        PHP);

        self::assertNull($orm->tableName);
        self::assertSame('unresolved', $orm->tableNameSource);
        self::assertFalse($orm->isComplete);
        self::assertStringContainsString('имя таблицы', (string)$orm->incompleteReason);
    }

    public function testClassWithoutGetMapIsNotAnOrmEntity(): void
    {
        [$extraction] = $this->extractor->extract("<?php\nclass Plain { public function a() {} }\n");

        self::assertNotNull($extraction);
        self::assertNull(
            $extraction->symbols[0]->orm,
            'Класс без getMap() не должен считаться ORM-сущностью',
        );
    }
}
