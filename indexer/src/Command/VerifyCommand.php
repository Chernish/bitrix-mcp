<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Command;

use BitrixMcp\Indexer\Core\Config;
use BitrixMcp\Indexer\ExitCode;
use BitrixMcp\Indexer\Preflight\PreflightFailure;
use BitrixMcp\Indexer\Storage\Database;
use BitrixMcp\Indexer\Storage\SchemaVersionGuard;
use PDO;

/**
 * Сверка индекса с контрольной выборкой.
 *
 * Проверяет ОБЕ стороны: существующие символы находятся с верной версией,
 * заведомо несуществующие — не находятся. Проверка только положительных
 * примеров недостаточна: система, отвечающая «найдено» на что угодно,
 * прошла бы её целиком (раздел конституции «Качество данных и проверка»).
 *
 * Ненулевой код возврата делает команду пригодной как гейт приёмки.
 */
final class VerifyCommand
{
    private const PRESENT_THRESHOLD = 0.95;

    /** @param array<string, string> $cli */
    public function run(array $cli, Reporter $out): ExitCode
    {
        $config = Config::resolve($cli);
        $fixturePath = $cli['fixtures'] ?? __DIR__ . '/../../tests/fixtures/known-symbols.json';

        if (!is_file($fixturePath)) {
            throw new PreflightFailure(
                "ОШИБКА: контрольная выборка не найдена: {$fixturePath}",
                ExitCode::ConfigError,
            );
        }

        $raw = file_get_contents($fixturePath);
        $fixture = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($fixture) || !isset($fixture['present'], $fixture['absent'])) {
            throw new PreflightFailure(
                "ОШИБКА: выборка {$fixturePath} повреждена",
                ExitCode::ConfigError,
            );
        }

        $pdo = Database::open($config->indexPath);
        SchemaVersionGuard::enforce($pdo, $config->indexPath);

        $out->line('Сверка с контрольной выборкой');
        $out->line('  выборка: ' . $fixturePath);
        $out->line('  индекс:  ' . $config->indexPath);
        $out->line('');

        [$presentOk, $presentFail] = $this->checkPresent($pdo, $fixture['present'], $out);
        [$absentOk, $absentFail] = $this->checkAbsent($pdo, $fixture['absent'], $out);

        $presentTotal = $presentOk + $presentFail;
        $absentTotal = $absentOk + $absentFail;
        $presentRate = $presentTotal > 0 ? $presentOk / $presentTotal : 1.0;

        $out->line('');
        $out->line(sprintf(
            'Существующие: %d/%d (%.1f%%), порог %.0f%% (SC-001)',
            $presentOk,
            $presentTotal,
            $presentRate * 100,
            self::PRESENT_THRESHOLD * 100,
        ));
        $out->line(sprintf(
            'Несуществующие: %d/%d, порог 100%% (SC-003)',
            $absentOk,
            $absentTotal,
        ));

        if ($presentRate < self::PRESENT_THRESHOLD || $absentFail > 0) {
            $out->line('');
            $out->line('РЕЗУЛЬТАТ: не пройдено');

            return ExitCode::ParseErrorThreshold;
        }

        $out->line('');
        $out->line('РЕЗУЛЬТАТ: пройдено');

        return ExitCode::Success;
    }

    /**
     * @param list<array<string, mixed>> $present
     * @return array{0: int, 1: int}
     */
    private function checkPresent(PDO $pdo, array $present, Reporter $out): array
    {
        $stmt = $pdo->prepare(
            'SELECT m.code AS module, m.version, f.rel_path, s.line, s.kind
             FROM symbol s
             JOIN module m ON m.id = s.module_id
             JOIN file   f ON f.id = s.file_id
             WHERE s.fqn = ? LIMIT 1'
        );

        $ok = 0;
        $fail = 0;

        foreach ($present as $entry) {
            $stmt->execute([$entry['fqn']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                $fail++;
                $out->line("  ✗ не найден: {$entry['fqn']}");
                continue;
            }

            $problems = [];

            if ($row['module'] !== $entry['module']) {
                $problems[] = "модуль {$row['module']} вместо {$entry['module']}";
            }

            // Версия — сердце проверки: без неё факт недостоверен (Принцип II).
            if ($row['version'] !== $entry['version']) {
                $problems[] = "версия {$row['version']} вместо {$entry['version']}";
            }

            if ($row['rel_path'] !== $entry['file']) {
                $problems[] = "файл {$row['rel_path']}";
            }

            if ((int)$row['line'] !== (int)$entry['line']) {
                $problems[] = "строка {$row['line']} вместо {$entry['line']}";
            }

            if ($problems === []) {
                $ok++;
                continue;
            }

            $fail++;
            $out->line("  ✗ {$entry['fqn']}: " . implode('; ', $problems));
        }

        return [$ok, $fail];
    }

    /**
     * @param list<string> $absent
     * @return array{0: int, 1: int}
     */
    private function checkAbsent(PDO $pdo, array $absent, Reporter $out): array
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM symbol WHERE short_name_lower = ? OR fqn_lower = ?'
        );

        $ok = 0;
        $fail = 0;

        foreach ($absent as $name) {
            $lower = mb_strtolower($name);
            $stmt->execute([$lower, '\\' . $lower]);

            if ((int)$stmt->fetchColumn() === 0) {
                $ok++;
                continue;
            }

            $fail++;
            $out->line("  ✗ найдено несуществующее имя: {$name}");
        }

        return [$ok, $fail];
    }
}
