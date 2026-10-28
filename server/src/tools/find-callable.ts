import type { IndexDatabase } from '../db/index.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

interface CallableRow {
  kind: 'function' | 'method';
  name: string;
  fqn: string;
  class_fqn: string | null;
  signature: string;
  module: string;
  module_version: string | null;
  rel_path: string;
  line: number;
  is_deprecated: number;
}

export function findCallableTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_find_callable',
    description:
      'Точный или префиксный поиск функции/метода по имени с точной сигнатурой. ' +
      'Фильтр модуля применяется ко всем результатам.',
    inputSchema: {
      type: 'object',
      properties: {
        name: { type: 'string' },
        module: { type: 'string' },
        kind: { type: 'string', enum: ['function', 'method', 'any'] },
        strict: { type: 'boolean', description: 'По умолчанию true; false включает префикс' },
        limit: { type: 'number', description: 'Максимум 20, по умолчанию 12' },
        offset: { type: 'number' },
      },
      required: ['name'],
    },
    handler: (args) => {
      const query = String(args.name ?? '').trim();
      if (!query) return notFound(db, query, 'invalid_query');
      const needle = query.replace(/\\+$/, '').split('\\').pop()?.toLowerCase() ?? '';
      const match = args.strict === false ? `${needle}%` : needle;
      const operator = args.strict === false ? 'LIKE' : '=';
      const kind = String(args.kind ?? 'any');
      const module = args.module ? String(args.module) : null;
      const limit = Math.min(Math.max(Number(args.limit ?? 12), 1), 20);
      const offset = Math.max(Number(args.offset ?? 0), 0);
      const fetch = offset + limit;
      const rows: CallableRow[] = [];
      let total = 0;

      if (kind !== 'function') {
        const where = [`me.name_lower ${operator} ?`];
        const params: unknown[] = [match];
        if (module) {
          where.push('mo.code = ?');
          params.push(module);
        }
        const from = `FROM method me JOIN symbol s ON s.id = me.symbol_id
                      JOIN module mo ON mo.id = s.module_id JOIN file f ON f.id = s.file_id
                      WHERE ${where.join(' AND ')}`;
        total += db.count(`SELECT COUNT(*) AS c ${from}`, params);
        rows.push(...db.all<CallableRow>(
          `SELECT 'method' AS kind, me.name, s.fqn || '::' || me.name AS fqn,
                  s.fqn AS class_fqn, me.signature, mo.code AS module,
                  mo.version AS module_version, f.rel_path, me.line, me.is_deprecated
           ${from} ORDER BY s.fqn, me.name LIMIT ?`,
          [...params, fetch],
        ));
      }

      if (kind === 'function' || kind === 'any') {
        const where = [`gf.short_name_lower ${operator} ?`];
        const params: unknown[] = [match];
        if (module) {
          where.push('mo.code = ?');
          params.push(module);
        }
        const from = `FROM global_function gf JOIN module mo ON mo.id = gf.module_id
                      JOIN file f ON f.id = gf.file_id WHERE ${where.join(' AND ')}`;
        total += db.count(`SELECT COUNT(*) AS c ${from}`, params);
        rows.push(...db.all<CallableRow>(
          `SELECT 'function' AS kind, gf.short_name AS name, gf.fqn, NULL AS class_fqn,
                  gf.signature, mo.code AS module, mo.version AS module_version,
                  f.rel_path, gf.line, gf.is_deprecated
           ${from} ORDER BY gf.fqn LIMIT ?`,
          [...params, fetch],
        ));
      }

      rows.sort((a, b) => a.fqn.localeCompare(b.fqn));
      const items = rows.slice(offset, offset + limit).map((row) => ({
        kind: row.kind,
        name: row.name,
        fqn: row.fqn,
        ...(row.class_fqn ? { class: row.class_fqn } : {}),
        signature: row.signature,
        module: row.module,
        module_version: row.module_version,
        version_unknown: row.module_version === null,
        deprecated: row.is_deprecated === 1,
        file: row.rel_path,
        line: row.line,
      }));

      if (items.length === 0) return notFound(db, query);
      return envelope(db, { found: true, query, items, total, truncated: offset + items.length < total });
    },
  };
}
