import type { IndexDatabase } from '../db/index.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

interface RawRest {
  name: string;
  handler: string | null;
  module: string;
  module_version: string | null;
  line: number;
  rel_path: string;
}

/**
 * Метод в выдаче.
 *
 * Модуль и версия проставляются на элементе ТОЛЬКО когда выдача охватывает
 * несколько модулей. При фильтре по одному модулю они уже есть в конверте
 * `provenance`, и повторять их на каждой строке — та же избыточность,
 * которую контрактный тест №2 ловил в предыдущих инструментах.
 * Принцип II при этом соблюдён: версия присутствует ровно один раз.
 */
interface RestOut {
  name: string;
  module?: string;
  module_version?: string | null;
  handler?: string;
}

export function restMethodsTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_list_rest_methods',
    description:
      'REST-методы, публикуемые модулями Битрикса наружу. ' +
      'Можно ограничить модулем или подстрокой в имени метода. ' +
      'Каждый метод сопровождается модулем-владельцем и его версией.',
    inputSchema: {
      type: 'object',
      properties: {
        module: { type: 'string', description: 'Код модуля, например calendar или tasks' },
        name_filter: { type: 'string', description: 'Подстрока в имени метода, например event' },
        limit: { type: 'number', description: 'Максимум методов, по умолчанию 30' },
        offset: { type: 'number', description: 'Смещение для следующей порции' },
        include_handler: {
          type: 'boolean',
          description: 'Добавить класс-обработчик. По умолчанию выключено ради экономии контекста',
        },
      },
    },
    handler: (args) => {
      const moduleCode = args.module ? String(args.module) : null;
      const nameFilter = args.name_filter ? String(args.name_filter).toLowerCase() : null;
      const limit = Number(args.limit ?? 30);
      const offset = Number(args.offset ?? 0);
      const withHandler = args.include_handler === true;

      const where: string[] = [];
      const params: unknown[] = [];

      if (moduleCode) {
        where.push('m.code = ?');
        params.push(moduleCode);
      }

      if (nameFilter) {
        where.push('r.name_lower LIKE ?');
        params.push('%' + nameFilter + '%');
      }

      const clause = where.length > 0 ? 'WHERE ' + where.join(' AND ') : '';

      const total = db.count(
        `SELECT COUNT(DISTINCT r.name_lower) AS c FROM rest_method r JOIN module m ON m.id = r.module_id ${clause}`,
        params,
      );

      if (total === 0) {
        return notFound(db, moduleCode ?? nameFilter ?? '(все)', 'not_in_snapshot');
      }

      const raw = db.all<RawRest>(
        `SELECT r.name, r.handler, r.line, m.code AS module, m.version AS module_version,
                (SELECT f.rel_path FROM file f WHERE f.id = r.file_id) AS rel_path
         FROM rest_method r
         JOIN module m ON m.id = r.module_id
         ${clause}
         GROUP BY r.name_lower
         ORDER BY r.name
         LIMIT ? OFFSET ?`,
        [...params, limit, offset],
      );

      const singleModule = moduleCode !== null;

      const methods: RestOut[] = raw.map((r) => {
        const out: RestOut = { name: r.name };

        if (!singleModule) {
          out.module = r.module;
          out.module_version = r.module_version;
        }

        if (withHandler && r.handler) out.handler = r.handler;

        return out;
      });

      // Атрибуция в конверте ставится только при однозначном владельце:
      // выдача может охватывать несколько модулей, и тогда версия указана
      // на каждом элементе.
      const single = moduleCode && raw[0] ? raw[0] : undefined;

      return envelope(
        db,
        {
          found: true,
          methods,
          total,
          truncated: offset + methods.length < total,
        },
        single ? { module: single.module, module_version: single.module_version } : {},
      );
    },
  };
}
