import type { IndexDatabase } from '../db/index.ts';
import { envelope } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

export function listModulesTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_list_modules',
    description:
      'Список проиндексированных модулей Битрикса с их собственными версиями. ' +
      'Выдача страничная; можно фильтровать по части кода.',
    inputSchema: {
      type: 'object',
      properties: {
        filter: { type: 'string', description: 'Подстрока в коде модуля' },
        limit: { type: 'number', description: 'Максимум 30, по умолчанию 20' },
        offset: { type: 'number', description: 'Смещение' },
      },
    },
    handler: (args) => {
      const filter = args.filter ? String(args.filter).toLowerCase() : null;
      const limit = Math.min(Math.max(Number(args.limit ?? 20), 1), 30);
      const offset = Math.max(Number(args.offset ?? 0), 0);
      const where = filter ? 'WHERE LOWER(m.code) LIKE ?' : '';
      const params: unknown[] = filter ? [`%${filter}%`] : [];
      const total = db.count(`SELECT COUNT(*) AS c FROM module m ${where}`, params);
      const modules = db.all<{
        code: string;
        version: string | null;
        rel_path: string;
        root_label: string;
      }>(
        `SELECT m.code, m.version, m.rel_path, r.label AS root_label
         FROM module m JOIN index_root r ON r.id = m.root_id
         ${where} ORDER BY m.code LIMIT ? OFFSET ?`,
        [...params, limit, offset],
      ).map((m) => ({
        code: m.code,
        version: m.version,
        version_unknown: m.version === null,
        root: m.root_label,
        path: m.rel_path,
      }));

      return envelope(db, {
        found: modules.length > 0,
        modules,
        total,
        truncated: offset + modules.length < total,
      });
    },
  };
}
