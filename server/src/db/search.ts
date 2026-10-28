import type { IndexDatabase } from './index.ts';

/** Максимум значимых токенов: ограничивает стоимость сверхдлинного запроса. */
export const MAX_QUERY_TOKENS = 12;

/** Верхние границы выдачи защищают бюджет контекста даже при ошибочном limit. */
export const MAX_CODE_RESULTS = 10;
export const MAX_DOC_RESULTS = 5;

/**
 * Общий разбор пользовательского запроса для обоих поисковых инструментов.
 *
 * Набор символов совпадает с базовой семантикой FTS5 unicode61: токенами
 * считаются последовательности Unicode-букв и цифр. Поэтому пунктуация и
 * операторы MATCH никогда не попадают в SQL, а дефис разделяет слова.
 */
export function tokenizeQuery(input: string): string[] {
  const words = input.match(/[\p{L}\p{N}]+/gu) ?? [];
  const seen = new Set<string>();
  const tokens: string[] = [];

  for (const word of words) {
    // Array.from считает Unicode-кодовые точки, а не UTF-16 code units.
    if (Array.from(word).length < 2) continue;

    const normalized = word.toLowerCase();
    if (seen.has(normalized)) continue;

    seen.add(normalized);
    tokens.push(word);

    if (tokens.length === MAX_QUERY_TOKENS) break;
  }

  return tokens;
}

/** Безопасный целочисленный limit в пределах контекстного бюджета. */
export function normalizeSearchLimit(value: unknown, fallback: number, maximum: number): number {
  const parsed = typeof value === 'number' ? value : Number(value);

  if (!Number.isFinite(parsed)) return fallback;

  return Math.min(maximum, Math.max(1, Math.trunc(parsed)));
}

/** Экранированный запрос ИЛИ для MATCH. Вход уже очищен tokenizeQuery. */
export function buildFtsQuery(tokens: string[]): string | null {
  if (tokens.length === 0) return null;

  return tokens.map((word) => `"${word}"`).join(' OR ');
}

interface RankedQuery {
  sql: string;
  params: unknown[];
}

/**
 * Строит точное двухуровневое ранжирование:
 *
 * 1. `hits` выполняет один индексный проход на каждый уникальный токен;
 *    GROUP BY получает число разных совпавших токенов для каждой строки.
 * 2. `scores` вычисляет bm25 для общего OR-запроса.
 * 3. Результат сортируется по покрытию, затем bm25 и rowid.
 *
 * Важно: кандидаты не отсекаются до подсчёта покрытия. Иначе документ со
 * всеми словами мог бы выпасть из предварительного top-N и оказаться ниже
 * частичного совпадения, нарушив FR-001.
 *
 * LIMIT в CTE `scores` равен максимальному SQLite INTEGER. Он не ограничивает
 * набор, но принудительно материализует CTE: FTS5 запрещает второй MATCH в том
 * же активном контексте виртуальной таблицы.
 */
export function buildRankedSearch(
  ftsTable: string,
  selectColumns: string,
  tokens: string[],
  joinClauses: string[],
  extraWhere: string[],
  extraParams: unknown[],
  limit: number,
): RankedQuery {
  const ftsQuery = buildFtsQuery(tokens);
  if (ftsQuery === null) {
    throw new Error('buildRankedSearch вызван без допустимых токенов');
  }

  const tokenSelects = tokens
    .map(() => `SELECT rowid AS rid FROM ${ftsTable} WHERE ${ftsTable} MATCH ?`)
    .join(' UNION ALL ');
  const where = extraWhere.length > 0 ? `WHERE ${extraWhere.join(' AND ')}` : '';

  const sql =
    `WITH scores AS (` +
    `SELECT rowid AS rid, bm25(${ftsTable}) AS score ` +
    `FROM ${ftsTable} WHERE ${ftsTable} MATCH ? ` +
    `ORDER BY score LIMIT 9223372036854775807` +
    `), hits AS (${tokenSelects}), coverage AS (` +
    `SELECT rid, COUNT(*) AS matched FROM hits GROUP BY rid` +
    `) ` +
    `SELECT ${selectColumns}, s.score, c.matched ` +
    `FROM coverage c ` +
    `JOIN scores s ON s.rid = c.rid ` +
    `JOIN ${ftsTable} f ON f.rowid = c.rid ${joinClauses.join(' ')} ` +
    `${where} ` +
    `ORDER BY c.matched DESC, s.score ASC, c.rid ASC ` +
    `LIMIT ?`;

  return {
    sql,
    params: [ftsQuery, ...tokens.map((word) => `"${word}"`), ...extraParams, limit],
  };
}

/** Общая точка выполнения ранжированного поиска для кода и документации. */
export function rankedSearch<T>(
  db: IndexDatabase,
  ftsTable: string,
  selectColumns: string,
  tokens: string[],
  joinClauses: string[],
  extraWhere: string[],
  extraParams: unknown[],
  limit: number,
): T[] {
  const query = buildRankedSearch(
    ftsTable,
    selectColumns,
    tokens,
    joinClauses,
    extraWhere,
    extraParams,
    limit,
  );

  // Ошибки БД не превращаются в ложное «не найдено»: после полной очистки
  // запроса синтаксическая ошибка MATCH означает дефект реализации/индекса.
  return db.all<T>(query.sql, query.params);
}
