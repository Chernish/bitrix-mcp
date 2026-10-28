import type { IndexDatabase } from '../db/index.ts';
import { envelope, notFound } from '../envelope.ts';
import {
  MAX_DOC_RESULTS,
  normalizeSearchLimit,
  rankedSearch,
  tokenizeQuery,
} from '../db/search.ts';
import type { ToolDefinition } from '../types.ts';

interface RawDoc {
  url: string;
  section: string;
  title: string | null;
  excerpt: string;
  version_mentions: string | null;
  source_ref: string | null;
}

interface DocOut {
  title: string | null;
  section: string;
  url: string;
  excerpt: string;
  version_notes?: VersionNoteOut[];
  source_ref?: string;
}

interface StoredVersionNote {
  version: string;
  subject_kind?: string;
  module?: string;
  subject?: string;
  heading?: string;
  statement?: string;
}

export interface VersionNoteOut {
  since: string;
  subject_kind: string;
  subject?: string;
  module?: string;
  installed_module_version?: string;
  availability: 'available' | 'requires_newer_module' | 'unknown';
  heading?: string;
  statement?: string;
}

const DOC_RESPONSE_BUDGET = 2_000;

/**
 * Экранирование и ранжирование запроса выполняются общим модулем поиска
 * (db/search.ts), единым для обоих поисковых инструментов.
 */
export function searchDocsTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_search_docs',
    description:
      'Поиск по концептуальной документации Битрикса: как правильно работать с ORM, ' +
      'требования безопасности, устройство кеша и подобное. Запросы на русском языке. ' +
      'Отвечает на вопросы «как правильно», которых нет в коде. ' +
      'Возвращает примечания «с версии» и сверяет версию указанного модуля с индексом. ' +
      'При расхождении документации с кодом истина за кодом.',
    inputSchema: {
      type: 'object',
      properties: {
        query: { type: 'string', description: 'Запрос на естественном языке, можно по-русски' },
        section: {
          type: 'string',
          description:
            'Ограничить разделом: framework, orm, security, performance, modules, ui и другие',
        },
        limit: { type: 'number', description: 'Максимум результатов, по умолчанию 5' },
        check_symbol: {
          type: 'string',
          description:
            'Имя класса или метода, упоминание которого нужно сверить с индексом кода. ' +
            'Если документация о нём говорит, а в сборке его нет, ответ содержит code_conflict',
        },
      },
      required: ['query'],
    },
    handler: (args) => {
      const query = String(args.query ?? '');
      const limit = normalizeSearchLimit(args.limit, 5, MAX_DOC_RESULTS);
      const section = args.section ? String(args.section) : null;

      if (db.snapshot.docs_crawled_at === null) {
        return notFound(db, query, 'index_missing');
      }

      const tokens = tokenizeQuery(query);
      if (tokens.length === 0) {
        return notFound(db, query, 'invalid_query');
      }

      const rows = rankedSearch<RawDoc>(
        db,
        'fts_doc',
        `d.url, d.section, d.title, d.version_mentions, d.source_ref,
                snippet(fts_doc, 1, '', '', '…', 14) AS excerpt`,
        tokens,
        ['JOIN doc_page d ON d.id = f.rowid'],
        section ? ['d.section = ?'] : [],
        section ? [section] : [],
        limit,
      );

      if (rows.length === 0) {
        return notFound(db, query, 'not_in_snapshot');
      }

      const moduleVersions = coreModuleVersions(db);
      const results: DocOut[] = rows.map((r) => {
        const out: DocOut = {
          title: r.title,
          section: r.section,
          url: r.url,
          excerpt: r.excerpt,
        };

        const notes = parseVersionNotes(r.version_mentions, moduleVersions);
        if (notes.length > 0) out.version_notes = notes;
        if (r.source_ref) out.source_ref = r.source_ref;

        return out;
      });

      const conflict = args.check_symbol ? checkConflict(db, String(args.check_symbol)) : null;
      const total = results.length;

      const makeResponse = () => {
        const payload: Record<string, unknown> = {
          found: true,
          results,
          count: results.length,
          docs_crawled_at: db.snapshot.docs_crawled_at,
          docs_source: db.snapshot.docs_source,
          docs_source_revision: db.snapshot.docs_source_revision,
          // Статья меняется со временем; version_notes — ограничения отдельных
          // возможностей, а не версия всей страницы.
          note:
            'Страницы документации не версионированы целиком; примечания о версиях ' +
            'относятся к указанным возможностям и модулям. ' +
            'Источник правды о наличии символа — индекс локального кода.',
        };

        if (results.length < total) {
          payload.truncated = true;
          payload.total_ranked = total;
        }
        if (conflict) payload.code_conflict = conflict;

        return envelope(db, payload);
      };

      let response = makeResponse();

      // Богатые version_notes не должны раздувать контекст. Сохраняем наиболее
      // релевантные строки и явно помечаем отсечение нижних результатов.
      while (JSON.stringify(response).length > DOC_RESPONSE_BUDGET && results.length > 1) {
        results.pop();
        response = makeResponse();
      }

      return response;
    },
  };
}

/** Читает версии именно модулей основного ядра, а не дополнительных корней. */
export function coreModuleVersions(db: IndexDatabase): Map<string, string> {
  const rows = db.all<{ code: string; version: string | null }>(
    `SELECT m.code, m.version FROM module m
     JOIN index_root r ON r.id = m.root_id
     WHERE r.is_core = 1 AND m.version IS NOT NULL`,
  );

  return new Map(rows.map((row) => [row.code.toLowerCase(), row.version ?? '']));
}

/**
 * Декодирует новый JSON и старые CSV-значения без миграции схемы индекса.
 * Сравнение выполняется только для явно распознанного модуля ядра.
 */
export function parseVersionNotes(
  raw: string | null,
  moduleVersions: ReadonlyMap<string, string>,
): VersionNoteOut[] {
  if (!raw) return [];

  let notes: StoredVersionNote[] = [];

  try {
    const decoded = JSON.parse(raw) as { schema?: unknown; notes?: unknown };

    if (
      (decoded.schema === 1 || decoded.schema === 2 || decoded.schema === 3 || decoded.schema === 4) &&
      Array.isArray(decoded.notes)
    ) {
      notes = decoded.notes.filter(
        (note): note is StoredVersionNote =>
          typeof note === 'object' && note !== null &&
          typeof (note as Record<string, unknown>).version === 'string',
      );
    }
  } catch {
    // Индексы до структурированного формата содержат "20.0, 24.100.0".
    notes = raw
      .split(',')
      .map((version) => version.trim())
      .filter((version) => /^\d+\.\d+(?:\.\d+)?$/.test(version))
      .map((version) => ({ version }));
  }

  return notes.map((note) => {
    const moduleCode = note.module?.toLowerCase();
    const installed = moduleCode ? moduleVersions.get(moduleCode) : undefined;
    const comparison = installed ? compareNumericVersions(installed, note.version) : null;
    const out: VersionNoteOut = {
      since: note.version,
      subject_kind: note.subject_kind ?? 'unknown',
      availability:
        comparison === null
          ? 'unknown'
          : comparison >= 0
            ? 'available'
            : 'requires_newer_module',
    };

    if (note.subject) out.subject = note.subject;
    if (note.module) out.module = note.module;
    if (installed) out.installed_module_version = installed;
    if (note.heading) out.heading = note.heading;
    if (note.statement) out.statement = note.statement;

    return out;
  });
}

/** Возвращает знак сравнения installed с required либо null для нечисловых версий. */
function compareNumericVersions(installed: string, required: string): number | null {
  if (!/^\d+(?:\.\d+){1,2}$/.test(installed) || !/^\d+(?:\.\d+){1,2}$/.test(required)) {
    return null;
  }

  const left = installed.split('.').map(Number);
  const right = required.split('.').map(Number);

  for (let i = 0; i < Math.max(left.length, right.length); i++) {
    const delta = (left[i] ?? 0) - (right[i] ?? 0);
    if (delta !== 0) return delta;
  }

  return 0;
}

/**
 * Проверка расхождения документации и кода (FR-030, Принцип I).
 *
 * Приоритет у кода, но расхождение обязано быть ВИДИМЫМ. Молчаливое
 * предпочтение одного источника лишает разработчика сигнала о том,
 * что документация устарела.
 */
function checkConflict(
  db: IndexDatabase,
  symbol: string,
): { symbol: string; doc_claims: true; present_in_snapshot: boolean; resolution: string } | null {
  const needle = symbol.replace(/^\\+/, '').toLowerCase();

  const present =
    db.count('SELECT COUNT(*) AS c FROM symbol WHERE short_name_lower = ? OR fqn_lower = ?', [
      needle,
      '\\' + needle,
    ]) > 0 ||
    db.count('SELECT COUNT(*) AS c FROM symbol_alias WHERE alias_short_lower = ?', [needle]) > 0 ||
    db.count('SELECT COUNT(*) AS c FROM method WHERE name_lower = ?', [needle]) > 0;

  if (present) return null;

  return {
    symbol,
    doc_claims: true,
    present_in_snapshot: false,
    resolution:
      'В проиндексированной сборке символа нет. Приоритет у кода: ' +
      'документация описывает текущее состояние продукта, а не вашу сборку.',
  };
}
