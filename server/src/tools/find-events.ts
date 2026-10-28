import type { IndexDatabase } from '../db/index.ts';
import { envelope } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

export function findEventsTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_find_events',
    description:
      'Поиск событий и точек их вызова/регистрации по всему ядру. Без фильтров работает ' +
      'как страничный общий список. owner_module — чьё событие; source_module — где найдена точка.',
    inputSchema: {
      type: 'object',
      properties: {
        name_filter: { type: 'string' },
        owner_module: { type: 'string' },
        source_module: { type: 'string' },
        kind: { type: 'string', enum: ['send', 'register', 'any'] },
        limit: { type: 'number', description: 'Максимум 20, по умолчанию 12' },
        offset: { type: 'number' },
      },
    },
    handler: (args) => {
      const where: string[] = [];
      const params: unknown[] = [];
      if (args.name_filter) {
        where.push('e.name_lower LIKE ?');
        params.push(`%${String(args.name_filter).toLowerCase()}%`);
      }
      if (args.owner_module) {
        where.push('e.owner_module = ?');
        params.push(String(args.owner_module));
      }
      if (args.source_module) {
        where.push('m.code = ?');
        params.push(String(args.source_module));
      }
      const kind = String(args.kind ?? 'any');
      if (kind !== 'any') {
        where.push('e.kind = ?');
        params.push(kind);
      }
      const clause = where.length ? `WHERE ${where.join(' AND ')}` : '';
      const from = `FROM event e JOIN module m ON m.id = e.module_id JOIN file f ON f.id = e.file_id ${clause}`;
      const limit = Math.min(Math.max(Number(args.limit ?? 12), 1), 20);
      const offset = Math.max(Number(args.offset ?? 0), 0);
      const total = db.count(`SELECT COUNT(*) AS c ${from}`, params);
      const events = db.all<{
        name: string;
        kind: string;
        owner_module: string;
        source_module: string;
        module_version: string | null;
        call_style: string;
        rel_path: string;
        line: number;
      }>(
        `SELECT e.name, e.kind, e.owner_module, m.code AS source_module,
                m.version AS module_version, e.call_style, f.rel_path, e.line
         ${from} ORDER BY e.name, m.code, e.line LIMIT ? OFFSET ?`,
        [...params, limit, offset],
      ).map((row) => ({
        name: row.name,
        kind: row.kind,
        owner_module: row.owner_module,
        source_module: row.source_module,
        source_module_version: row.module_version,
        version_unknown: row.module_version === null,
        style: row.call_style,
        file: row.rel_path,
        line: row.line,
      }));

      return envelope(db, { found: events.length > 0, events, total, truncated: offset + events.length < total });
    },
  };
}
