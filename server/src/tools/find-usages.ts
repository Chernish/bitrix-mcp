import type { IndexDatabase } from '../db/index.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

export function findUsagesTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_find_usages',
    description:
      'Найти AST-точки вызова функции или метода. Для статических вызовов возвращается ' +
      'целевой класс; для $object->method() он честно помечается dynamic.',
    inputSchema: {
      type: 'object',
      properties: {
        name: { type: 'string', description: 'Имя функции или метода' },
        class: { type: 'string', description: 'Точный класс для static call' },
        source_module: { type: 'string', description: 'Где искать вызовы' },
        kind: { type: 'string', enum: ['function', 'static_method', 'method', 'any'] },
        include_dynamic: { type: 'boolean', description: 'По умолчанию true' },
        limit: { type: 'number', description: 'Максимум 20, по умолчанию 12' },
        offset: { type: 'number' },
      },
      required: ['name'],
    },
    handler: (args) => {
      const query = String(args.name ?? '').trim();
      if (!query) return notFound(db, query, 'invalid_query');
      const member = query.split('::').pop()?.split('\\').pop()?.toLowerCase() ?? '';
      const where = ['ur.member_name_lower = ?'];
      const params: unknown[] = [member];
      if (args.class) {
        const target = '\\' + String(args.class).replace(/^\\+/, '').toLowerCase();
        where.push('ur.target_fqn_lower = ?');
        params.push(target);
      }
      if (args.source_module) {
        where.push('m.code = ?');
        params.push(String(args.source_module));
      }
      const kind = String(args.kind ?? 'any');
      if (kind !== 'any') {
        where.push('ur.kind = ?');
        params.push(kind);
      }
      if (args.include_dynamic === false) where.push('ur.is_dynamic = 0');
      const clause = where.join(' AND ');
      const from = `FROM usage_reference ur JOIN module m ON m.id = ur.module_id
                    JOIN file f ON f.id = ur.file_id WHERE ${clause}`;
      const limit = Math.min(Math.max(Number(args.limit ?? 12), 1), 20);
      const offset = Math.max(Number(args.offset ?? 0), 0);
      const total = db.count(`SELECT COUNT(*) AS c ${from}`, params);
      const usages = db.all<{
        kind: string;
        target_fqn: string | null;
        member_name: string;
        context_fqn: string | null;
        context_member: string | null;
        is_dynamic: number;
        module: string;
        module_version: string | null;
        rel_path: string;
        line: number;
      }>(
        `SELECT ur.kind, ur.target_fqn, ur.member_name, ur.context_fqn,
                ur.context_member, ur.is_dynamic, m.code AS module,
                m.version AS module_version, f.rel_path, ur.line
         ${from} ORDER BY m.code, f.rel_path, ur.line LIMIT ? OFFSET ?`,
        [...params, limit, offset],
      ).map((row) => ({
        kind: row.kind,
        target: row.target_fqn,
        member: row.member_name,
        dynamic_target: row.is_dynamic === 1,
        context: row.context_fqn,
        context_member: row.context_member,
        source_module: row.module,
        source_module_version: row.module_version,
        version_unknown: row.module_version === null,
        file: row.rel_path,
        line: row.line,
      }));

      if (usages.length === 0) return notFound(db, query);
      return envelope(db, { found: true, query, usages, total, truncated: offset + usages.length < total });
    },
  };
}
