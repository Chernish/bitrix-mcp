<?
// НАМЕРЕННО короткий открывающий тег — как в 27 652 файлах реального ядра.
// НЕ переписывать на `<?php`: это уничтожит смысл фикстуры (R-001).

class CTestModLegacy
{
    public static function GetList($order = "ID", $by = "ASC")
    {
        return false;
    }
}
