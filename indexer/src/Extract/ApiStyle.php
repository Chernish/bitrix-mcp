<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Extract;

/**
 * Поколение API, к которому относится символ.
 *
 * ВНИМАНИЕ: это ЭВРИСТИКА, а не факт. Она определяется по форме имени
 * и хранится отдельно от достоверного признака `is_deprecated`, читаемого
 * из PHPDoc.
 *
 * Это единственное задокументированное отклонение от Принципа III конституции
 * (см. plan.md → Complexity Tracking). Оно допустимо ровно потому, что догадка
 * остаётся отличимой от факта: достоверные сигналы покрывают меньшинство
 * старого API (751 файл с @deprecated и 184 класса CAll* против нескольких
 * тысяч классов вида CIBlockElement, не помеченных никак), а без различения
 * поколений агент продолжит путать CCrmDeal с \Bitrix\Crm\.
 */
enum ApiStyle: string
{
    /** Пространство имён Bitrix\ — современное объектное API */
    case D7 = 'd7';

    /** Без пространства имён, имя вида CXxx — старое процедурное API */
    case Legacy = 'legacy';

    /** Форма имени не даёт основания для вывода */
    case Unknown = 'unknown';

    public static function detect(?string $namespace, string $shortName): self
    {
        if ($namespace !== null && $namespace !== '') {
            return str_starts_with($namespace, 'Bitrix\\') || $namespace === 'Bitrix'
                ? self::D7
                : self::Unknown;
        }

        // CCrmDeal, CIBlockElement, CAllMain — характерная форма старого ядра.
        // Требуется заглавная буква после C, иначе под правило попадут
        // безобидные имена вроде Cache или Config.
        if (preg_match('/^C[A-Z]/', $shortName) === 1) {
            return self::Legacy;
        }

        return self::Unknown;
    }
}
