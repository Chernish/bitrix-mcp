import type { IndexDatabase } from '../db/index.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

interface NamespaceRow {
  kind: string;
  fqn: string;
  signature: string | null;
  module: string;
  module_version: string | null;
  rel_path: string;
  line: number;
}

export function findNamespaceTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_find_namespace',
    description:
      'Найти классы, интерфейсы, трейты, enum и функции в PHP namespace по всему ядру. ' +
      'Граница namespace учитывается: Bitrix\\Main\\IO не совпадаёт с Bitrix\\Main\\IOMaybe.',
    inputSchema: {
      type: 'object',
      properties: {
        namespace: { type: 'string' },
        module: { type: 'string' },
        kind: { type: 'string', enum: ['class', 'interface', 'trait', 'enum', 'function', 'any'] },
        limit: { type: 'number', description: 'Максимум 25, по умолчанию 15' },
        offset: { type: 'number' },
      },
      required: ['namespace'],
    },
    handler: (args) => {
      const query = String(args.namespace ?? '').trim().replace(/^\\+|\\+$/g, '');
      if (!query) return notFound(db, query, 'invalid_query');
      const prefix = `\\${query.toLowerCase()}\\%`;
      const module = args.module ? String(args.module) : null;
      const kind = String(args.kind ?? 'any');
      const limit = Math.min(Math.max(Number(args.limit ?? 15), 1), 25);
      const offset = Math.max(Number(args.offset ?? 0), 0);
      const fetch = offset + limit;
      const rows: NamespaceRow[] = [];
      let total = 0;

      if (kind !== 'function') {
        const where = ['s.fqn_lower LIKE ? ESCAPE \'!\''];
        const params: unknown[] = [prefix];
        if (kind !== 'any') {
          where.push('s.kind = ?');
          params.push(kind);
        }
        if (module) {
          where.push('m.code = ?');
          params.push(module);
        }
        const from = `FROM symbol s JOIN module m ON m.id = s.module_id
                      JOIN file f ON f.id = s.file_id WHERE ${where.join(' AND ')}`;
        total += db.count(`SELECT COUNT(*) AS c ${from}`, params);
        rows.push(...db.all<NamespaceRow>(
          `SELECT s.kind, s.fqn, NULL AS signature, m.code AS module,
                  m.version AS module_version, f.rel_path, s.line
           ${from} ORDER BY s.fqn LIMIT ?`,
          [...params, fetch],
        ));
      }

      if (kind === 'function' || kind === 'any') {
        const where = ['gf.fqn_lower LIKE ?'];
        const params: unknown[] = [prefix];
        if (module) {
          where.push('m.code = ?');
          params.push(module);
        }
        const from = `FROM global_function gf JOIN module m ON m.id = gf.module_id
                      JOIN file f ON f.id = gf.file_id WHERE ${where.join(' AND ')}`;
        total += db.count(`SELECT COUNT(*) AS c ${from}`, params);
        rows.push(...db.all<NamespaceRow>(
          `SELECT 'function' AS kind, gf.fqn, gf.signature, m.code AS module,
                  m.version AS module_version, f.rel_path, gf.line
           ${from} ORDER BY gf.fqn LIMIT ?`,
          [...params, fetch],
        ));
      }

      rows.sort((a, b) => a.fqn.localeCompare(b.fqn));
      const items = rows.slice(offset, offset + limit).map((row) => ({
        kind: row.kind,
        fqn: row.fqn,
        ...(row.signature ? { signature: row.signature } : {}),
        module: row.module,
        module_version: row.module_version,
        version_unknown: row.module_version === null,
        file: row.rel_path,
        line: row.line,
      }));

      if (items.length === 0) return notFound(db, query);
      return envelope(db, { found: true, namespace: `\\${query}`, items, total, truncated: offset + items.length < total });
    },
  };
}
