import type { IndexDatabase } from '../db/index.ts';
import { docSummary, lookupSymbol } from '../db/symbols.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

export function getClassConstantsTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_get_class_constants',
    description:
      'Константы класса с их статическим представлением значения, visibility, файлом и строкой. ' +
      'Выражения не выполняются.',
    inputSchema: {
      type: 'object',
      properties: {
        class: { type: 'string' },
        name_filter: { type: 'string' },
        visibility: { type: 'string', enum: ['public', 'protected', 'private', 'any'] },
        include_docs: { type: 'boolean' },
        limit: { type: 'number', description: 'Максимум 25, по умолчанию 15' },
        offset: { type: 'number' },
      },
      required: ['class'],
    },
    handler: (args) => {
      const query = String(args.class ?? '');
      const symbol = lookupSymbol(db, query, { limit: 1 }).symbols[0];
      if (!symbol) return notFound(db, query);
      const rawSymbol = db.get<{ id: number }>('SELECT id FROM symbol WHERE fqn = ? LIMIT 1', [symbol.canonical_fqn]);
      if (!rawSymbol) return notFound(db, query);

      const where = ['symbol_id = ?'];
      const params: unknown[] = [rawSymbol.id];
      const visibility = String(args.visibility ?? 'public');
      if (visibility !== 'any') {
        where.push('visibility = ?');
        params.push(visibility);
      }
      if (args.name_filter) {
        where.push('name_lower LIKE ?');
        params.push(`%${String(args.name_filter).toLowerCase()}%`);
      }
      const clause = where.join(' AND ');
      const limit = Math.min(Math.max(Number(args.limit ?? 15), 1), 25);
      const offset = Math.max(Number(args.offset ?? 0), 0);
      const total = db.count(`SELECT COUNT(*) AS c FROM class_constant WHERE ${clause}`, params);
      const constants = db.all<{
        name: string;
        visibility: string;
        is_final: number;
        value_repr: string;
        doc_comment: string | null;
        line: number;
      }>(
        `SELECT name, visibility, is_final, value_repr, doc_comment, line
         FROM class_constant WHERE ${clause} ORDER BY name LIMIT ? OFFSET ?`,
        [...params, limit, offset],
      ).map((c) => ({
        name: c.name,
        visibility: c.visibility,
        final: c.is_final === 1,
        value: c.value_repr,
        line: c.line,
        ...(args.include_docs === true && docSummary(c.doc_comment, 100)
          ? { doc_summary: docSummary(c.doc_comment, 100) }
          : {}),
      }));

      return envelope(
        db,
        { found: true, class: symbol.canonical_fqn, file: symbol.file, constants, total, truncated: offset + constants.length < total },
        { module: symbol.module, module_version: symbol.module_version },
      );
    },
  };
}
