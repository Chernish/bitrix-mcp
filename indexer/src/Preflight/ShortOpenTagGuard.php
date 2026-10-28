<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Preflight;

use BitrixMcp\Indexer\ExitCode;

/**
 * Отказывает в запуске индексации при выключенном short_open_tag.
 *
 * Это не предупреждение и не деградация, а полный отказ, и вот почему.
 *
 * 27 652 файла ядра из 67 835 (41%) открываются коротким тегом `<?`, а не `<?php`.
 * Настройка short_open_tag влияет на token_get_all(), поэтому при её выключении
 * парсер НЕ выдаёт ошибку — он возвращает такой файл единственным узлом InlineHTML.
 * Это синтаксически корректный результат, содержащий ноль объявлений.
 *
 * Индексатор счёл бы такой файл успешно обработанным и пустым. На выборке 3000
 * файлов так терялось 2122 файла и 15% классов, причём отчёт об индексации
 * выглядел безупречно.
 *
 * Последствие — нарушение сразу двух принципов конституции: III (молчаливая потеря
 * данных запрещена) и I, потому что существующий в ядре символ отдавался бы
 * как «не найдено», превращая честный отказ в ложь.
 *
 * Измерения: specs/001-bitrix-core-mcp/research.md, R-001.
 *
 * Эмулировать настройку невозможно: php-parser опирается на token_get_all(),
 * который читает её из INI. Предобработка `<?` в `<?php` тоже не годится — она
 * сдвигает смещения и ломает номера строк, требуемые FR-004.
 */
final class ShortOpenTagGuard
{
    /**
     * @param bool|null $override Принудительное значение для тестов.
     *                            null — читать настоящую настройку INI.
     */
    public function __construct(
        private readonly ?bool $override = null,
    ) {
    }

    public function isEnabled(): bool
    {
        if ($this->override !== null) {
            return $this->override;
        }

        return filter_var(ini_get('short_open_tag'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @throws PreflightFailure если настройка выключена
     */
    public function enforce(): void
    {
        if ($this->isEnabled()) {
            return;
        }

        throw new PreflightFailure(self::message(), ExitCode::ShortOpenTagDisabled);
    }

    public static function message(): string
    {
        return <<<TXT
        ОШИБКА: short_open_tag выключен.

        При этой настройке 27 652 файла ядра (41% от 67 835) разбираются как HTML
        без единой ошибки и молча теряются из индекса. Индексация завершится
        «успешно», но индекс будет неполным, а сервер начнёт отвечать «не найдено»
        на существующие классы.

        Запустите с явным флагом:
            php -d short_open_tag=1 indexer/bin/index.php <команда>

        Либо через Makefile, где флаг проставлен во всех целях:
            make build
        TXT;
    }
}
