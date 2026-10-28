import type { IndexDatabase } from '../db/index.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

export function getModuleTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_get_module',
    description:
      'Структура и фактические зависимости модуля, его версия и счётчики API. ' +
      'Зависимости извлечены из Loader::includeModule(), а не предположены.',
    inputSchema: {
      type: 'object',
      properties: {
        module: { type: 'string', description: 'Код модуля' },
        dependency_limit: { type: 'number', description: 'Максимум зависимостей, по умолчанию 15' },
      },
      required: ['module'],
    },
    handler: (args) => {
      const code = String(args.module ?? '');
      const limit = Math.min(Math.max(Number(args.dependency_limit ?? 15), 1), 25);
      const module = db.get<{
        id: number;
        code: string;
        version: string | null;
        version_date: string | null;
        version_source: string;
        rel_path: string;
        root: string;
      }>(
        `SELECT m.id, m.code, m.version, m.version_date, m.version_source, m.rel_path,
                r.label AS root
         FROM module m JOIN index_root r ON r.id = m.root_id
         WHERE m.code = ? LIMIT 1`,
        [code],
      );

      if (!module) return notFound(db, code);

      const dependencies = db.all<{ code: string; occurrences: number }>(
        `SELECT depends_on_code AS code, occurrences FROM module_dependency
         WHERE module_id = ? ORDER BY occurrences DESC, code LIMIT ?`,
        [module.id, limit],
      );
      const dependencyTotal = db.count(
        'SELECT COUNT(*) AS c FROM module_dependency WHERE module_id = ?',
        [module.id],
      );

      const counts = {
        files: db.count('SELECT COUNT(*) AS c FROM file WHERE module_id = ?', [module.id]),
        symbols: db.count('SELECT COUNT(*) AS c FROM symbol WHERE module_id = ?', [module.id]),
        functions: db.count('SELECT COUNT(*) AS c FROM global_function WHERE module_id = ?', [module.id]),
        methods: db.count(
          'SELECT COUNT(*) AS c FROM method me JOIN symbol s ON s.id = me.symbol_id WHERE s.module_id = ?',
          [module.id],
        ),
        orm_entities: db.count(
          'SELECT COUNT(*) AS c FROM orm_entity oe JOIN symbol s ON s.id = oe.symbol_id WHERE s.module_id = ?',
          [module.id],
        ),
        components: db.count('SELECT COUNT(*) AS c FROM component WHERE module_id = ?', [module.id]),
        rest_methods: db.count('SELECT COUNT(*) AS c FROM rest_method WHERE module_id = ?', [module.id]),
      };

      return envelope(
        db,
        {
          found: true,
          code: module.code,
          version: module.version,
          version_date: module.version_date,
          version_source: module.version_source,
          root: module.root,
          path: module.rel_path,
          counts,
          dependencies,
          dependency_total: dependencyTotal,
          dependencies_truncated: dependencies.length < dependencyTotal,
        },
        { module: module.code, module_version: module.version },
      );
    },
  };
}
