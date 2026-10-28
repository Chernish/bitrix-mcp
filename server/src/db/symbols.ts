import type { IndexDatabase } from './index.ts';
import type { SymbolRow } from '../types.ts';

interface RawSymbol {
  id: number;
  fqn: string;
  short_name: string;
  kind: string;
  module: string;
  module_version: string | null;
  parent_fqn: string | null;
  is_deprecated: number;
  deprecated_note: string | null;
  api_style: string;
  rel_path: string;
  line: number;
  doc_comment: string | null;
}

const SELECT_SYMBOL = `
  SELECT s.id, s.fqn, s.short_name, s.kind, s.parent_fqn, s.is_deprecated,
         s.deprecated_note, s.api_style, s.line, s.doc_comment,
         m.code AS module, m.version AS module_version, f.rel_path
  FROM symbol s
  JOIN module m ON m.id = s.module_id
  JOIN file   f ON f.id = s.file_id
`;

function normalize(name: string): string {
  return name.replace(/^\\+/, '').toLowerCase();
}

function withLeadingSlash(name: string): string {
  return '\\' + name.replace(/^\\+/, '');
}

/** Первая содержательная строка PHPDoc. Целый блок агенту не отдаём (Принцип IV). */
export function docSummary(doc: string | null, maxLength = 200): string | null {
  if (!doc) return null;

  for (const raw of doc.split(/\r?\n/)) {
    const clean = raw.trim().replace(/^(\/\*\*|\*\/|\*)\s*/, '').trim();

    if (clean === '' || clean.startsWith('@')) continue;

    return clean.length > maxLength ? clean.slice(0, maxLength - 1) + '…' : clean;
  }

  return null;
}

function hydrate(db: IndexDatabase, raw: RawSymbol, canonicalFqn?: string): SymbolRow {
  const interfaces = db
    .all<{ interface_fqn: string }>(
      'SELECT interface_fqn FROM symbol_interface WHERE symbol_id = ?',
      [raw.id],
    )
    .map((r) => r.interface_fqn);

  return {
    fqn: raw.fqn,
    canonical_fqn: canonicalFqn ?? raw.fqn,
    short_name: raw.short_name,
    kind: raw.kind,
    module: raw.module,
    module_version: raw.module_version,
    version_unknown: raw.module_version == null,
    parent_fqn: raw.parent_fqn,
    interfaces,
    is_deprecated: raw.is_deprecated === 1,
    deprecated_note: raw.deprecated_note,
    api_style: raw.api_style,
    api_style_is_heuristic: true,
    file: raw.rel_path,
    line: raw.line,
    method_count: db.count('SELECT COUNT(*) AS c FROM method WHERE symbol_id = ?', [raw.id]),
    doc_summary: docSummary(raw.doc_comment),
  };
}

export interface LookupOptions {
  module?: string;
  kind?: string;
  limit?: number;
}

export interface LookupResult {
  symbols: SymbolRow[];
  total: number;
  truncated: boolean;
}

/**
 * Поиск символа по имени.
 *
 * Порядок: точное совпадение полного имени → точное совпадение короткого →
 * разрешение псевдонима class_alias → префиксное совпадение.
 *
 * Разрешение псевдонимов обязательно: \Bitrix\Sale\OrderTable — форма,
 * которой пользуются повсеместно, но объявления с таким именем в ядре нет
 * (R-006). Без этого шага система отвечала бы «не найдено» на существующий
 * и общеупотребительный класс.
 */
export function lookupSymbol(
  db: IndexDatabase,
  name: string,
  options: LookupOptions = {},
): LookupResult {
  const limit = options.limit ?? 10;
  const needle = normalize(name);

  const filters: string[] = [];
  const extra: unknown[] = [];

  if (options.module) {
    filters.push('m.code = ?');
    extra.push(options.module);
  }

  if (options.kind) {
    filters.push('s.kind = ?');
    extra.push(options.kind);
  }

  const and = filters.length > 0 ? ' AND ' + filters.join(' AND ') : '';

  // 1. Полное имя.
  let rows = db.all<RawSymbol>(
    `${SELECT_SYMBOL} WHERE s.fqn_lower = ?${and} ORDER BY m.code, s.fqn`,
    ['\\' + needle, ...extra],
  );

  // 2. Короткое имя — самый частый путь: агент знает OrderTable, а не полное имя.
  if (rows.length === 0) {
    rows = db.all<RawSymbol>(
      `${SELECT_SYMBOL} WHERE s.short_name_lower = ?${and} ORDER BY m.code, s.fqn`,
      [needle, ...extra],
    );
  }

  // 3. Псевдоним class_alias.
  if (rows.length === 0) {
    const alias = db.get<{ target_fqn: string; alias_fqn: string }>(
      'SELECT target_fqn, alias_fqn FROM symbol_alias WHERE alias_fqn_lower = ? OR alias_short_lower = ? LIMIT 1',
      ['\\' + needle, needle],
    );

    if (alias) {
      const target = normalize(alias.target_fqn);
      const targetRows = db.all<RawSymbol>(
        `${SELECT_SYMBOL} WHERE s.fqn_lower = ?${and} ORDER BY m.code`,
        ['\\' + target, ...extra],
      );

      if (targetRows.length > 0) {
        const hydrated = targetRows.map((r) =>
          hydrate(db, { ...r, fqn: withLeadingSlash(alias.alias_fqn) }, r.fqn),
        );

        return { symbols: hydrated.slice(0, limit), total: hydrated.length, truncated: false };
      }
    }
  }

  // 4. Префикс.
  if (rows.length === 0) {
    rows = db.all<RawSymbol>(
      `${SELECT_SYMBOL} WHERE s.short_name_lower LIKE ?${and} ORDER BY LENGTH(s.short_name), m.code LIMIT ?`,
      [needle + '%', ...extra, limit * 4],
    );
  }

  const total = rows.length;
  const page = rows.slice(0, limit);

  return {
    symbols: page.map((r) => hydrate(db, r)),
    total,
    // Усечение всегда явное: молчаливая обрезка читается как «это всё» (Принцип IV).
    truncated: total > page.length,
  };
}

/** Похожие имена как ДОГАДКА для отдельного поля, не как результат. */
export function similarNames(db: IndexDatabase, name: string, limit = 5): string[] {
  const needle = normalize(name);
  const prefix = needle.slice(0, Math.max(3, Math.floor(needle.length / 2)));

  return db
    .all<{ short_name: string }>(
      `SELECT DISTINCT short_name FROM symbol
       WHERE short_name_lower LIKE ? AND short_name_lower <> ?
       ORDER BY LENGTH(short_name) LIMIT ?`,
      [prefix + '%', needle, limit],
    )
    .map((r) => r.short_name);
}
