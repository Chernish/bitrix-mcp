<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Core;

/**
 * Откуда взялась версия модуля.
 *
 * Различение источников — не диагностика, а требование Принципа II.
 * `Unknown` обязан оставаться отдельным состоянием: подстановка версии сборки
 * вместо неустановленной версии модуля превращает честное незнание
 * в уверенную ложь и прямо запрещена.
 */
enum VersionSource: string
{
    /** Прочитана из modules/<code>/install/version.php */
    case ModuleFile = 'module_file';

    /** Модуль main: версия задана константой SM_VERSION */
    case SmVersion = 'sm_version';

    /** Установить не удалось */
    case Unknown = 'unknown';
}
