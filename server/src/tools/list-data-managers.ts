import type { IndexDatabase } from '../db/index.ts';
import { envelope } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

export function listDataManagersTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_list_data_managers',
    description:
      'Список ORM DataManager-сущностей по всему ядру или модулю. Показывает таблицу, ' +
      'число полей и честный признак полноты статического разбора.',
    inputSchema: {
      type: 'object',
      properties: {
        module: { type: 'string' },
        name_filter: { type: 'string' },
        limit: { type: 'number', description: 'Максимум 20, по умолчанию 12' },
        offset: { type: 'number' },
      },
    },
    handler: (args) => {
      const where: string[] = [];
      const params: unknown[] = [];
      if (args.module) {
        where.push('m.code = ?');
        params.push(String(args.module));
      }
      if (args.name_filter) {
        where.push('s.fqn_lower LIKE ?');
        params.push(`%${String(args.name_filter).toLowerCase()}%`);
      }
      const clause = where.length ? `WHERE ${where.join(' AND ')}` : '';
      const from = `FROM orm_entity oe
                    JOIN symbol s ON s.id = oe.symbol_id
                    JOIN module m ON m.id = s.module_id
                    JOIN file f ON f.id = s.file_id ${clause}`;
      const limit = Math.min(Math.max(Number(args.limit ?? 12), 1), 20);
      const offset = Math.max(Number(args.offset ?? 0), 0);
      const total = db.count(`SELECT COUNT(*) AS c ${from}`, params);
      const entities = db.all<{
        id: number;
        fqn: string;
        table_name: string | null;
        is_complete: number;
        module: string;
        module_version: string | null;
        rel_path: string;
        line: number;
        field_count: number;
      }>(
        `SELECT oe.id, s.fqn, oe.table_name, oe.is_complete, m.code AS module,
                m.version AS module_version, f.rel_path, s.line,
                (SELECT COUNT(*) FROM orm_field ofi WHERE ofi.orm_entity_id = oe.id) AS field_count
         ${from} ORDER BY s.fqn LIMIT ? OFFSET ?`,
        [...params, limit, offset],
      ).map((row) => ({
        fqn: row.fqn,
        table: row.table_name,
        fields: row.field_count,
        complete: row.is_complete === 1,
        module: row.module,
        module_version: row.module_version,
        version_unknown: row.module_version === null,
        file: row.rel_path,
        line: row.line,
      }));

      return envelope(db, {
        found: entities.length > 0,
        entities,
        total,
        truncated: offset + entities.length < total,
      });
    },
  };
}
