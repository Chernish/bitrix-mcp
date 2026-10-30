import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import type { IndexDatabase } from './db/index.ts';

/**
 * Обнаружение рассинхрона индекса и ядра.
 *
 * Проверка сравнивает версию сборки и версии всех проиндексированных модулей.
 * Результат кэшируется на пять секунд и используется как обычными ответами,
 * так и подробной диагностикой, чтобы их флаги stale не расходились.
 *
 * Ручную правку отдельного файла ядра эта проверка не ловит — для этого есть
 * полная сверка при переиндексации командой update.
 *
 * ВАЖНО: константы объявлены в modules/main/classes/general/version.php.
 * Файла modules/main/install/version.php в ядре НЕТ (research.md, R-013).
 */

const VERSION_FILE_CANDIDATES = [
  'modules/main/classes/general/version.php',
  'modules/main/install/version.php',
];

export interface StalenessResult {
  stale: boolean;
  reason: string | null;
}

/** Кэш на время жизни процесса: файлы версий перечитываются не чаще интервала. */
let cached: { at: number; result: DeepStalenessResult } | null = null;
const CACHE_MS = 5000;

export function readCurrentSmVersion(coreRoot: string): string | null {
  for (const candidate of VERSION_FILE_CANDIDATES) {
    const path = join(coreRoot, candidate);

    if (!existsSync(path)) continue;

    try {
      const content = readFileSync(path, 'utf8');
      // Проза константы, а не структурный факт кода: регулярное выражение
      // здесь уместно и Принципу III не противоречит. Полный разбор AST ради
      // одного define() на каждый запрос был бы неоправданно дорог.
      const match = /define\(\s*["']SM_VERSION["']\s*,\s*["']([^"']+)["']/.exec(content);

      if (match?.[1]) return match[1];
    } catch {
      // Недоступность файла — не повод падать: она сама по себе признак
      // того, что с ядром что-то не так, и об этом сообщит вызывающий.
      continue;
    }
  }

  return null;
}

export function checkStaleness(db: IndexDatabase, now: number = Date.now()): StalenessResult {
  const { stale, reason } = evaluate(db, now);

  return { stale, reason };
}

export function resetStalenessCache(): void {
  cached = null;
}

export interface DeepStalenessResult extends StalenessResult {
  /** Модули, у которых версия в ядре разошлась с записанной в индексе. */
  drifted: Array<{ module: string; indexed: string | null; current: string | null }>;
  checked: number;
}

/** Тот же результат, что у обычной проверки, плюс подробная карта модулей. */
export function checkDeepStaleness(db: IndexDatabase): DeepStalenessResult {
  return evaluate(db, Date.now());
}

function readModuleVersion(path: string): string | null {
  if (!existsSync(path)) return null;

  try {
    const content = readFileSync(path, 'utf8');
    const match = /['"]VERSION['"]\s*=>\s*['"]([^'"]+)['"]/.exec(content);

    return match?.[1] ?? null;
  } catch {
    return null;
  }
}

function evaluate(db: IndexDatabase, now: number): DeepStalenessResult {
  if (cached && now - cached.at < CACHE_MS) {
    return cached.result;
  }

  const result = compute(db);
  cached = { at: now, result };

  return result;
}

function compute(db: IndexDatabase): DeepStalenessResult {
  const coreRoot = db.coreRoot();

  if (coreRoot === null) {
    return { stale: false, reason: null, drifted: [], checked: 0 };
  }

  if (!existsSync(coreRoot)) {
    return {
      stale: true,
      reason: `корень ядра недоступен по записанному пути: ${coreRoot}`,
      drifted: [],
      checked: 0,
    };
  }

  const currentSm = readCurrentSmVersion(coreRoot);
  const indexedSm = db.snapshot.sm_version;
  const modules = db.all<{ code: string; version: string | null; rel_path: string }>(
    'SELECT code, version, rel_path FROM module ORDER BY code',
  );
  const drifted: DeepStalenessResult['drifted'] = [];
  const reasons: string[] = [];

  if (currentSm !== null && indexedSm !== null && currentSm !== indexedSm) {
    reasons.push(`sm_version изменился: ${indexedSm} -> ${currentSm}`);
  }

  for (const row of modules) {
    const current =
      row.code === 'main'
        ? currentSm
        : readModuleVersion(join(coreRoot, row.rel_path, 'install', 'version.php'));

    if (current !== row.version) {
      drifted.push({ module: row.code, indexed: row.version, current });
    }
  }

  if (drifted.length > 0) {
    const names = drifted.slice(0, 5).map((d) => d.module).join(', ');
    const suffix = drifted.length > 5 ? ` и ещё ${drifted.length - 5}` : '';
    reasons.push(`версии модулей разошлись: ${names}${suffix}`);
  }

  return {
    stale: reasons.length > 0,
    reason: reasons.length > 0 ? reasons.join('; ') : null,
    drifted,
    checked: modules.length,
  };
}
