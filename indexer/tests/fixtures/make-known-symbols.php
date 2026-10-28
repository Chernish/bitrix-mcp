<?php

declare(strict_types=1);

/**
 * Генератор контрольной выборки для команды verify.
 *
 * Запускается вручную при существенном обновлении ядра, не при каждой сборке.
 *
 * ВАЖНО про достоверность. Кандидаты берутся из индекса, но КАЖДЫЙ из них
 * затем сверяется с исходным файлом: объявление символа должно реально
 * присутствовать в записанном файле на записанной строке. Без этой сверки
 * выборка была бы слепком индекса и проверяла бы саму себя.
 *
 * Использование:
 *   php -d short_open_tag=1 tests/fixtures/make-known-symbols.php <index.sqlite> <core-root>
 */

$indexPath = $argv[1] ?? '../index.sqlite';
$coreRoot = $argv[2] ?? getenv('BITRIX_CORE_ROOT');

if (!is_file($indexPath) || !is_string($coreRoot) || !is_dir($coreRoot)) {
    fwrite(STDERR, "Использование: make-known-symbols.php <index.sqlite> <core-root>\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $indexPath);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Кандидаты из разных модулей и обоих поколений API — выборка должна
// покрывать не один благополучный случай, а спектр.
$candidates = $pdo->query(
    "SELECT s.fqn, s.short_name, s.kind, s.api_style, s.line,
            m.code AS module, m.version, f.rel_path
     FROM symbol s
     JOIN module m ON m.id = s.module_id
     JOIN file   f ON f.id = s.file_id
     WHERE m.version IS NOT NULL
     GROUP BY m.code, s.api_style
     ORDER BY m.code
     LIMIT 200"
)->fetchAll();

$verified = [];

foreach ($candidates as $row) {
    if (count($verified) >= 60) {
        break;
    }

    $file = rtrim($coreRoot, '/') . '/' . $row['rel_path'];

    if (!is_file($file)) {
        continue;
    }

    $lines = file($file);

    if ($lines === false) {
        continue;
    }

    $declaration = $lines[$row['line'] - 1] ?? '';

    // Сверка с исходником: имя обязано встречаться в строке объявления.
    if (!str_contains($declaration, $row['short_name'])) {
        fwrite(STDERR, "пропуск (не подтверждено исходником): {$row['fqn']}\n");
        continue;
    }

    $verified[] = [
        'fqn' => $row['fqn'],
        'short_name' => $row['short_name'],
        'kind' => $row['kind'],
        'module' => $row['module'],
        'version' => $row['version'],
        'file' => $row['rel_path'],
        'line' => (int)$row['line'],
        'api_style' => $row['api_style'],
    ];
}

// Заведомо несуществующие имена. Проверка только положительных примеров
// недостаточна: система, отвечающая «найдено» на всё, прошла бы её целиком
// (раздел конституции «Качество данных и проверка»).
$absent = [];
$patterns = ['CTotallyMadeUp', 'CNonExistent', 'CFabricated', 'CImaginary', 'CPhantom'];

foreach ($patterns as $prefix) {
    foreach (['Deal', 'Element', 'Order', 'Table', 'Manager', 'Service'] as $suffix) {
        $absent[] = $prefix . $suffix . 'Xyz';
    }
}

$payload = [
    'generated_for_sm_version' => $pdo->query('SELECT sm_version FROM snapshot WHERE id = 1')->fetchColumn(),
    'note' => 'Каждая запись present[] подтверждена сверкой со строкой объявления в исходном файле.',
    'present' => $verified,
    'absent' => $absent,
];

file_put_contents(
    __DIR__ . '/known-symbols.json',
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
);

printf("подтверждено символов: %d, несуществующих имён: %d\n", count($verified), count($absent));
