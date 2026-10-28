<?php

/**
 * Точка входа индексатора.
 *
 * ВАЖНО: запускать с -d short_open_tag=1. Без этого команды, разбирающие код,
 * откажутся работать (код возврата 2). Причина — в src/Preflight/ShortOpenTagGuard.php.
 */

declare(strict_types=1);

use BitrixMcp\Indexer\Command\BuildCommand;
use BitrixMcp\Indexer\Command\DocsCommand;
use BitrixMcp\Indexer\Command\Reporter;
use BitrixMcp\Indexer\Command\SnapshotsCommand;
use BitrixMcp\Indexer\Command\StatusCommand;
use BitrixMcp\Indexer\Command\UpdateCommand;
use BitrixMcp\Indexer\Command\VerifyCommand;
use BitrixMcp\Indexer\Command\VersionedCommand;
use BitrixMcp\Indexer\ExitCode;
use BitrixMcp\Indexer\Preflight\PreflightFailure;

$autoload = __DIR__ . '/../vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "ОШИБКА: зависимости не установлены. Выполните: cd indexer && composer install\n");
    exit(ExitCode::ConfigError->value);
}

require $autoload;

/**
 * Разбор аргументов вида `команда --ключ=значение --флаг`.
 *
 * @param list<string> $argv
 * @return array{0: string, 1: array<string, string>}
 */
$parseArgv = static function (array $argv): array {
    $command = 'help';
    $options = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, '--')) {
            $body = substr($arg, 2);
            $eq = strpos($body, '=');

            if ($eq === false) {
                $options[$body] = '1';
            } else {
                $options[substr($body, 0, $eq)] = substr($body, $eq + 1);
            }

            continue;
        }

        if ($command === 'help') {
            $command = $arg;
        }
    }

    return [$command, $options];
};

$usage = static function (Reporter $out): void {
    $out->line('Индексатор ядра 1С-Битрикс');
    $out->line('');
    $out->line('Использование:');
    $out->line('    php -d short_open_tag=1 indexer/bin/index.php <команда> [опции]');
    $out->line('');
    $out->line('Команды:');
    $out->line('    build     полная индексация');
    $out->line('    update    инкрементальная переиндексация');
    $out->line('    docs      сбор документации         [--force] [--docs-source=git|web]');
    $out->line('    status    состояние индекса      [--deep] [--json]');
    $out->line('    verify    сверка с контрольной выборкой');
    $out->line('    snapshots           список снимков         [--json]');
    $out->line('    snapshot-archive    архивировать активный индекс');
    $out->line('    snapshot-use        активировать снимок     --id=SNAPSHOT_ID');
    $out->line('    snapshot-delete     удалить архивный снимок  --id=SNAPSHOT_ID');
    $out->line('');
    $out->line('Опции:');
    $out->line('    --core=PATH         корень ядра (или BITRIX_CORE_ROOT)');
    $out->line('    --index=PATH        файл индекса (или BITRIX_MCP_INDEX)');
    $out->line('    --extra-roots=A:B   дополнительные корни (или BITRIX_EXTRA_ROOTS)');
    $out->line('    --docs-repo=URL     Git-репозиторий документации (или локальный путь)');
    $out->line('    --docs-ref=REF      ветка/тег документации, по умолчанию main');
    $out->line('    --docs-cache=PATH   каталог локального Git-кэша');
    $out->line('');
    $out->line('Проще через Makefile — там флаг short_open_tag уже проставлен:');
    $out->line('    make build');
};

[$command, $options] = $parseArgv($argv ?? []);
$out = new Reporter();

try {
    $code = match ($command) {
        'build' => (new VersionedCommand(new BuildCommand()))->run($options, $out),
        'update' => (new VersionedCommand(new UpdateCommand()))->run($options, $out),
        'docs' => (new VersionedCommand(new DocsCommand()))->run($options, $out),
        'status' => (new StatusCommand())->run($options, $out),
        'verify' => (new VerifyCommand())->run($options, $out),
        'snapshots' => (new SnapshotsCommand())->list($options, $out),
        'snapshot-archive' => (new SnapshotsCommand())->archive($options, $out),
        'snapshot-use' => (new SnapshotsCommand())->activate($options, $out),
        'snapshot-delete' => (new SnapshotsCommand())->delete($options, $out),
        default => (static function () use ($out, $usage): ExitCode {
            $usage($out);

            return ExitCode::Success;
        })(),
    };
} catch (PreflightFailure $e) {
    $out->line('');
    $out->line($e->getMessage());
    exit($e->exitCode->value);
} catch (Throwable $e) {
    $out->line('');
    $out->line('НЕОЖИДАННАЯ ОШИБКА: ' . $e->getMessage());
    $out->line('  ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
}

exit($code->value);
