import type { IndexDatabase } from '../db/index.ts';
import { lookupSymbol } from '../db/symbols.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

export function findImplementationsTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_find_implementations',
    description:
      'Найти классы и enum, которые напрямую реализуют указанный интерфейс. ' +
      'Импорты и namespace уже разрешены AST-индексатором.',
    inputSchema: {
      type: 'object',
      properties: {
        interface: { type: 'string', description: 'Короткое или полное имя' },
        module: { type: 'string', description: 'Ограничить модулем реализации' },
        limit: { type: 'number', description: 'Максимум 25, по умолчанию 15' },
        offset: { type: 'number' },
      },
      required: ['interface'],
    },
    handler: (args) => {
      const query = String(args.interface ?? '');
      const iface = lookupSymbol(db, query, { kind: 'interface', limit: 1 }).symbols[0];
      const interfaceFqn = iface?.canonical_fqn ?? ('\\' + query.replace(/^\\+/, ''));
      const where = ['LOWER(si.interface_fqn) = ?'];
      const params: unknown[] = [interfaceFqn.toLowerCase()];
      if (args.module) {
        where.push('m.code = ?');
        params.push(String(args.module));
      }
      const clause = where.join(' AND ');
      const limit = Math.min(Math.max(Number(args.limit ?? 15), 1), 25);
      const offset = Math.max(Number(args.offset ?? 0), 0);
      const base = `FROM symbol_interface si
                    JOIN symbol s ON s.id = si.symbol_id
                    JOIN module m ON m.id = s.module_id
                    JOIN file f ON f.id = s.file_id
                    WHERE ${clause}`;
      const total = db.count(`SELECT COUNT(*) AS c ${base}`, params);
      const implementations = db.all<{
        fqn: string;
        kind: string;
        module: string;
        module_version: string | null;
        rel_path: string;
        line: number;
      }>(
        `SELECT s.fqn, s.kind, m.code AS module, m.version AS module_version,
                f.rel_path, s.line ${base}
         ORDER BY s.fqn LIMIT ? OFFSET ?`,
        [...params, limit, offset],
      ).map((row) => ({
        fqn: row.fqn,
        kind: row.kind,
        module: row.module,
        module_version: row.module_version,
        version_unknown: row.module_version === null,
        file: row.rel_path,
        line: row.line,
      }));

      if (implementations.length === 0) return notFound(db, query);

      return envelope(db, {
        found: true,
        interface: interfaceFqn,
        declaration_in_snapshot: iface !== undefined,
        implementations,
        total,
        truncated: offset + implementations.length < total,
      });
    },
  };
}
