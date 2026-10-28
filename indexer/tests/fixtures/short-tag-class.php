<?
// Фикстура для регрессии R-001. НАМЕРЕННО открывается коротким тегом `<?`.
// Так устроены 27 652 файла ядра Битрикса (41% от 67 835).
//
// При short_open_tag=Off парсер вернёт весь файл одним узлом InlineHTML,
// и класс ниже станет невидимым — без единой ошибки. Тесты обязаны это ловить.
//
// НЕ переписывать на `<?php`: это уничтожит смысл фикстуры.

class CShortTagLegacy
{
    const STATUS_ACTIVE = 'Y';

    public static function GetList($order = [], $filter = [], $limit = 50)
    {
        return [];
    }

    public function getName(): string
    {
        return 'legacy';
    }

    protected function hidden(int &$counter, string ...$rest): ?array
    {
        return null;
    }
}
