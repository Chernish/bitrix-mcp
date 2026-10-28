import type { IndexDatabase } from '../db/index.ts';
import { docSummary, lookupSymbol } from '../db/symbols.ts';
import { envelope, notFound } from '../envelope.ts';
import type { MethodRow, ToolDefinition } from '../types.ts';

/**
 * Умолчание подобрано под бюджет ответа из SC-007 — 2000 символов.
 *
 * Исходно стояло 40, и контрактный тест №2 это поймал: IblockTable отдавал
 * 3181 символ. Арифметика простая — 2000 символов на 40 методов дают 50 символов
 * на метод, а одна сигнатура нередко длиннее. Двадцать методов с сигнатурами
 * в бюджет укладываются, а остальное агент догрузит через offset.
 */
const DEFAULT_LIMIT = 20;

interface RawMethod {
  id: number;
  name: string;
  signature: string;
  visibility: string;
  is_static: number;
  return_type: string | null;
  line: number;
  doc_comment: string | null;
  is_deprecated: number;
}

export function getClassTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_get_class',
    description:
      'Получить методы класса ядра Битрикса с точными сигнатурами. ' +
      'Возвращает сигнатуру, файл и строку — не содержимое файла. ' +
      'Выдача ограничена и страничная: усечение всегда помечено явно.',
    inputSchema: {
      type: 'object',
      properties: {
        fqn: { type: 'string', description: 'Имя класса, полное или короткое' },
        visibility: {
          type: 'string',
          enum: ['public', 'protected', 'private', 'any'],
          description: 'Фильтр видимости, по умолчанию public',
        },
        name_filter: { type: 'string', description: 'Подстрока в имени метода' },
        limit: { type: 'number', description: `Максимум методов, по умолчанию ${DEFAULT_LIMIT}` },
        offset: { type: 'number', description: 'Смещение для следующей порции' },
        include_docs: {
          type: 'boolean',
          description: 'Добавить краткое описание из PHPDoc. По умолчанию выключено ради экономии контекста',
        },
      },
      required: ['fqn'],
    },
    handler: (args) => {
      const fqn = String(args.fqn ?? '');
      const { symbols } = lookupSymbol(db, fqn, { limit: 1 });
      const symbol = symbols[0];

      if (!symbol) {
        return notFound(db, fqn, 'not_in_snapshot');
      }

      const row = db.get<{ id: number }>(
        'SELECT s.id FROM symbol s WHERE s.fqn = ? LIMIT 1',
        [symbol.canonical_fqn],
      );

      if (!row) {
        return notFound(db, fqn, 'not_in_snapshot');
      }

      const visibility = String(args.visibility ?? 'public');
      const limit = Number(args.limit ?? DEFAULT_LIMIT);
      const offset = Number(args.offset ?? 0);
      const nameFilter = args.name_filter ? String(args.name_filter).toLowerCase() : null;

      const where: string[] = ['symbol_id = ?'];
      const params: unknown[] = [row.id];

      if (visibility !== 'any') {
        where.push('visibility = ?');
        params.push(visibility);
      }

      if (nameFilter) {
        where.push('name_lower LIKE ?');
        params.push('%' + nameFilter + '%');
      }

      const clause = where.join(' AND ');
      const total = db.count(`SELECT COUNT(*) AS c FROM method WHERE ${clause}`, params);

      const raw = db.all<RawMethod>(
        `SELECT id, name, signature, visibility, is_static, return_type, line, doc_comment, is_deprecated
         FROM method WHERE ${clause} ORDER BY name LIMIT ? OFFSET ?`,
        [...params, limit, offset],
      );

      // Описания по умолчанию не отдаются: сигнатура уже содержит имена
      // и типы параметров, а PHPDoc удваивает объём ответа. Агент запросит
      // их явно, если сигнатуры окажется мало.
      const includeDocs = args.include_docs === true;

      const methods: MethodRow[] = raw.map((m) => {
        const row: MethodRow = { name: m.name, signature: m.signature, line: m.line };

        if (m.is_deprecated === 1) row.deprecated = true;

        if (includeDocs) {
          const summary = docSummary(m.doc_comment, 120);
          if (summary) row.doc_summary = summary;
        }

        return row;
      });

      return envelope(
        db,
        {
          found: true,
          fqn: symbol.fqn,
          canonical_fqn: symbol.canonical_fqn,
          kind: symbol.kind,
          parent_fqn: symbol.parent_fqn,
          interfaces: symbol.interfaces,
          is_deprecated: symbol.is_deprecated,
          api_style: symbol.api_style,
          api_style_is_heuristic: true as const,
          file: symbol.file,
          methods,
          total,
          truncated: offset + methods.length < total,
        },
        { module: symbol.module, module_version: symbol.module_version },
      );
    },
  };
}
