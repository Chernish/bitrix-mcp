import type { IndexDatabase } from '../db/index.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

interface ApiRow {
  kind: string;
  fqn: string;
  name: string;
  signature: string | null;
  rel_path: string;
  line: number;
}

export function listModuleApiTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_list_module_api',
    description:
      'Страничный список классов, интерфейсов, трейтов, enum и функций модуля. ' +
      'Для функций возвращается точная сигнатура.',
    inputSchema: {
      type: 'object',
      properties: {
        module: { type: 'string' },
        kind: {
          type: 'string',
          enum: ['class', 'interface', 'trait', 'enum', 'function', 'any'],
        },
        name_filter: { type: 'string' },
        limit: { type: 'number', description: 'Максимум 25, по умолчанию 15' },
        offset: { type: 'number' },
      },
      required: ['module'],
    },
    handler: (args) => {
      const code = String(args.module ?? '');
      const kind = String(args.kind ?? 'any');
      const filter = args.name_filter ? String(args.name_filter).toLowerCase() : null;
      const limit = Math.min(Math.max(Number(args.limit ?? 15), 1), 25);
      const offset = Math.max(Number(args.offset ?? 0), 0);
      const module = db.get<{ id: number; code: string; version: string | null }>(
        'SELECT id, code, version FROM module WHERE code = ? LIMIT 1',
        [code],
      );
      if (!module) return notFound(db, code);

      const rows: ApiRow[] = [];
      if (kind !== 'function') {
        const where = ['s.module_id = ?'];
        const params: unknown[] = [module.id];
        if (kind !== 'any') {
          where.push('s.kind = ?');
          params.push(kind);
        }
        if (filter) {
          where.push('(s.short_name_lower LIKE ? OR s.fqn_lower LIKE ?)');
          params.push(`%${filter}%`, `%${filter}%`);
        }
        rows.push(...db.all<ApiRow>(
          `SELECT s.kind, s.fqn, s.short_name AS name, NULL AS signature,
                  f.rel_path, s.line
           FROM symbol s JOIN file f ON f.id = s.file_id
           WHERE ${where.join(' AND ')}`,
          params,
        ));
      }
      if (kind === 'function' || kind === 'any') {
        const where = ['gf.module_id = ?'];
        const params: unknown[] = [module.id];
        if (filter) {
          where.push('(gf.short_name_lower LIKE ? OR gf.fqn_lower LIKE ?)');
          params.push(`%${filter}%`, `%${filter}%`);
        }
        rows.push(...db.all<ApiRow>(
          `SELECT 'function' AS kind, gf.fqn, gf.short_name AS name, gf.signature,
                  f.rel_path, gf.line
           FROM global_function gf JOIN file f ON f.id = gf.file_id
           WHERE ${where.join(' AND ')}`,
          params,
        ));
      }

      rows.sort((a, b) => a.fqn.localeCompare(b.fqn));
      const items = rows.slice(offset, offset + limit).map((row) => ({
        kind: row.kind,
        fqn: row.fqn,
        name: row.name,
        ...(row.signature ? { signature: row.signature } : {}),
        file: row.rel_path,
        line: row.line,
      }));

      return envelope(
        db,
        { found: items.length > 0, module: code, items, total: rows.length, truncated: offset + items.length < rows.length },
        { module: module.code, module_version: module.version },
      );
    },
  };
}
