import type { IndexDatabase } from '../db/index.ts';
import { docSummary } from '../db/symbols.ts';
import { envelope, notFound } from '../envelope.ts';
import {
  MAX_CODE_RESULTS,
  normalizeSearchLimit,
  rankedSearch,
  tokenizeQuery,
} from '../db/search.ts';
import type { ToolDefinition } from '../types.ts';

interface RawHit {
  fqn: string;
  member: string;
  signature: string;
  doc: string | null;
  module: string;
  rel_path: string;
  line: number;
}

interface HitOut {
  fqn: string;
  member?: string;
  signature: string;
  module: string;
  module_version: string | null;
  file: string;
  line: number;
  summary?: string;
}

const CODE_RESPONSE_BUDGET = 2_000;

export function searchCodeTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_search_code',
    description:
      'Поиск по коду ядра Битрикса по ОПИСАНИЮ задачи, когда точное имя неизвестно. ' +
      'ВАЖНО: PHPDoc в ядре Битрикса написан ПО-АНГЛИЙСКИ, поэтому запрос тоже ' +
      'должен быть на английском: «send mail», «access rights», «cache invalidation». ' +
      'Русский запрос почти наверняка ничего не найдёт — для русского используйте ' +
      'bitrix_search_docs, документация русскоязычная. ' +
      'Если имя класса или метода известно, точнее bitrix_find_symbol. ' +
      'Возвращает сигнатуру, файл и строку, но не содержимое файлов. ' +
      'Документы со всеми словами запроса стоят выше документов с частичными совпадениями.',
    inputSchema: {
      type: 'object',
      properties: {
        query: {
          type: 'string',
          description:
            'Описание задачи ПО-АНГЛИЙСКИ: "send mail", "access rights", "cache invalidation". ' +
            'PHPDoc ядра англоязычный, русские запросы результата не дадут',
        },
        module: { type: 'string', description: 'Ограничить модулем, например crm' },
        limit: { type: 'number', description: 'Максимум результатов, по умолчанию 6' },
      },
      required: ['query'],
    },
    handler: (args) => {
      const query = String(args.query ?? '');
      const limit = normalizeSearchLimit(args.limit, 6, MAX_CODE_RESULTS);
      const moduleCode = args.module ? String(args.module) : null;

      const tokens = tokenizeQuery(query);
      if (tokens.length === 0) {
        return notFound(db, query, 'invalid_query');
      }

      const rows = rankedSearch<RawHit>(
        db,
        'fts_code',
        'f.fqn, f.member, f.signature, f.doc, f.module, f.rel_path, f.line',
        tokens,
        [],
        moduleCode ? ['module = ?'] : [],
        moduleCode ? [moduleCode] : [],
        limit,
      );

      if (rows.length === 0) {
        // Самая частая причина пустой выдачи — запрос по-русски. Подсказать
        // об этом дешевле, чем оставить агента гадать.
        const hasCyrillic = /[а-яё]/i.test(query);

        return envelope(db, {
          found: false,
          reason: 'not_in_snapshot' as const,
          query,
          ...(hasCyrillic
            ? {
                hint:
                  'Запрос содержит кириллицу, а PHPDoc ядра написан по-английски. ' +
                  'Повторите запрос на английском либо используйте bitrix_search_docs.',
              }
            : {}),
        });
      }

      // Версии подтягиваются одним запросом: выдача охватывает разные модули,
      // а Принцип II требует версию у каждого факта.
      const versions = new Map(
        db
          .all<{ code: string; version: string | null }>('SELECT code, version FROM module')
          .map((m) => [m.code, m.version]),
      );

      const results: HitOut[] = rows.map((r) => {
        const out: HitOut = {
          fqn: r.fqn,
          signature: r.signature,
          module: r.module,
          module_version: versions.get(r.module) ?? null,
          file: r.rel_path,
          line: r.line,
        };

        if (r.member !== '') out.member = r.member;

        const summary = docSummary(r.doc, 90);
        if (summary) out.summary = summary;

        return out;
      });

      const total = results.length;
      const makeResponse = () =>
        envelope(db, {
          found: true,
          results,
          count: results.length,
          ...(results.length < total ? { truncated: true, total_ranked: total } : {}),
        });

      let response = makeResponse();

      while (JSON.stringify(response).length > CODE_RESPONSE_BUDGET && results.length > 1) {
        results.pop();
        response = makeResponse();
      }

      return response;
    },
  };
}
