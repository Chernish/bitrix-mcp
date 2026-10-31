import type { IndexDatabase } from '../db/index.ts';
import { COMPONENT_GROUPS } from '../db/components.ts';
import { envelope } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

export function listComponentsTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_list_components',
    description:
      'Найти и перечислить компоненты Битрикса. В отличие от bitrix_get_component, ' +
      'не выгружает параметры, а даёт компактную навигацию.',
    inputSchema: {
      type: 'object',
      properties: {
        module: { type: 'string' },
        name_filter: { type: 'string' },
        limit: { type: 'number', description: 'Максимум 25, по умолчанию 15' },
        offset: { type: 'number' },
      },
    },
    handler: (args) => {
      const where: string[] = [];
      const params: unknown[] = [];
      if (args.module) {
        where.push('module = ?');
        params.push(String(args.module));
      }
      if (args.name_filter) {
        where.push('LOWER(full_name) LIKE ?');
        params.push(`%${String(args.name_filter).toLowerCase()}%`);
      }
      const clause = where.length ? `WHERE ${where.join(' AND ')}` : '';
      const limit = Math.min(Math.max(Number(args.limit ?? 15), 1), 25);
      const offset = Math.max(Number(args.offset ?? 0), 0);
      const total = db.count(`SELECT COUNT(*) AS c FROM (${COMPONENT_GROUPS}) grouped ${clause}`, params);
      const components = db.all<{
        full_name: string;
        title: string | null;
        params_resolved: number;
        module: string;
        module_version: string | null;
        rel_path: string;
        param_count: number;
      }>(
        `SELECT full_name, title, params_resolved, module, module_version, rel_path, param_count
         FROM (${COMPONENT_GROUPS}) grouped ${clause}
         ORDER BY full_name, module, root_id LIMIT ? OFFSET ?`,
        [...params, limit, offset],
      ).map((c) => ({
        name: c.full_name,
        title: c.title,
        module: c.module,
        module_version: c.module_version,
        version_unknown: c.module_version === null,
        params: c.param_count,
        params_resolved: c.params_resolved === 1,
        file: c.rel_path,
      }));

      return envelope(db, {
        found: components.length > 0,
        components,
        total,
        truncated: offset + components.length < total,
      });
    },
  };
}
