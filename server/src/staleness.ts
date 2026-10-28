import { readFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';
import type { IndexDatabase } from './db/index.ts';

/**
 * Обнаружение рассинхрона индекса и ядра.
 *
 * Дешёвый уровень: одно чтение файла с константами версии сборки и сверка
 * с записанным в снимке. Он ловит штатное обновление Битрикса, которое всегда
 * меняет SM_VERSION.
 *
 * Ручную правку отдельного файла ядра этот уровень не ловит — для этого есть
 * полная сверка при переиндексации. Такая правка нарушает саму модель поставки
 * и относится к сценарию, на который рассчитана команда update.
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

/** Кэш на время жизни процесса: файл читается не чаще, чем раз в интервал. */
let cached: { at: number; result: StalenessResult } | null = null;
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
  if (cached && now - cached.at < CACHE_MS) {
    return cached.result;
  }

  const result = compute(db);
  cached = { at: now, result };

  return result;
}

export function resetStalenessCache(): void {
  cached = null;
}

export interface DeepStalenessResult extends StalenessResult {
  /** Модули, у которых версия в ядре разошлась с записанной в индексе. */
  drifted: Array<{ module: string; indexed: string | null; current: string | null }>;
  checked: number;
}

/**
 * Второй уровень: перечитывание версий всех модулей.
 *
 * Дороже дешёвой сверки (93 чтения файлов против одного), поэтому вызывается
 * по явному запросу, а не на каждый ответ. Ловит случай, который первый уровень
 * пропускает: обновление отдельного модуля без смены версии сборки.
 *
 * Третий уровень — полная сверка размеров, времён и хешей всех файлов —
 * принадлежит команде `update` индексатора: сервер только читает и не может
 * привести индекс в соответствие, а значит и обходить дерево ему незачем.
 */
export function checkDeepStaleness(db: IndexDatabase): DeepStalenessResult {
  const base = compute(db);
  const coreRoot = db.coreRoot();

  if (coreRoot === null || !existsSync(coreRoot)) {
    return { ...base, drifted: [], checked: 0 };
  }

  const indexed = db.all<{ code: string; version: string | null; rel_path: string }>(
    'SELECT code, version, rel_path FROM module ORDER BY code',
  );

  const drifted: DeepStalenessResult['drifted'] = [];

  for (const row of indexed) {
    const current =
      row.code === 'main'
        ? readCurrentSmVersion(coreRoot)
        : readModuleVersion(join(coreRoot, row.rel_path, 'install', 'version.php'));

    if (current !== row.version) {
      drifted.push({ module: row.code, indexed: row.version, current });
    }
  }

  if (drifted.length === 0) {
    return { ...base, drifted, checked: indexed.length };
  }

  const names = drifted.slice(0, 5).map((d) => d.module).join(', ');
  const suffix = drifted.length > 5 ? ` и ещё ${drifted.length - 5}` : '';

  return {
    stale: true,
    reason: base.reason ?? `версии модулей разошлись: ${names}${suffix}`,
    drifted,
    checked: indexed.length,
  };
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

function compute(db: IndexDatabase): StalenessResult {
  const coreRoot = db.coreRoot();

  if (coreRoot === null) {
    return { stale: false, reason: null };
  }

  if (!existsSync(coreRoot)) {
    return {
      stale: true,
      reason: `корень ядра недоступен по записанному пути: ${coreRoot}`,
    };
  }

  const current = readCurrentSmVersion(coreRoot);
  const indexed = db.snapshot.sm_version;

  if (current === null || indexed === null) {
    return { stale: false, reason: null };
  }

  return current === indexed
    ? { stale: false, reason: null }
    : { stale: true, reason: `sm_version изменился: ${indexed} -> ${current}` };
}
