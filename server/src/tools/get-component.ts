import type { IndexDatabase } from '../db/index.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

interface RawComponent {
  id: number;
  full_name: string;
  namespace: string;
  name: string;
  title: string | null;
  description: string | null;
  params_resolved: number;
  module: string;
  module_version: string | null;
  rel_path: string;
}

interface RawParam {
  code: string;
  type: string | null;
  default_repr: string | null;
  is_default_resolved: number;
  parent_group: string | null;
  multiple: number;
  values_repr: string | null;
}

interface ParamOut {
  code: string;
  type?: string;
  default?: string;
  /** Значение вычисляется в рантайме и статически недоступно. */
  default_unresolved?: true;
  group?: string;
  multiple?: true;
  values?: string;
}

const SELECT_COMPONENT = `
  SELECT c.id, c.full_name, c.namespace, c.name, c.title, c.description, c.params_resolved,
         m.code AS module, m.version AS module_version, f.rel_path
  FROM component c
  JOIN module m ON m.id = c.module_id
  JOIN file   f ON f.id = c.file_id
`;

export function getComponentTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_get_component',
    description:
      'Параметры компонента Битрикса по идентификатору вида bitrix:main.feedback. ' +
      'Часть значений по умолчанию вычисляется в рантайме и потому недоступна — ' +
      'такие помечены default_unresolved, а их число отражено в unresolved_count. ' +
      'Это ожидаемое состояние: файлы компонентов не выполняются.',
    inputSchema: {
      type: 'object',
      properties: {
        name: {
          type: 'string',
          description: 'Идентификатор компонента (bitrix:main.feedback) или короткое имя (main.feedback)',
        },
        limit: { type: 'number', description: 'Максимум параметров, по умолчанию 25' },
        offset: { type: 'number', description: 'Смещение для следующей порции' },
      },
      required: ['name'],
    },
    handler: (args) => {
      const query = String(args.name ?? '');
      const limit = Number(args.limit ?? 25);
      const offset = Number(args.offset ?? 0);

      const component =
        db.get<RawComponent>(`${SELECT_COMPONENT} WHERE c.full_name = ? LIMIT 1`, [query]) ??
        db.get<RawComponent>(`${SELECT_COMPONENT} WHERE c.name = ? LIMIT 1`, [query]);

      if (!component) {
        return notFound(db, query, 'not_in_snapshot');
      }

      const total = db.count('SELECT COUNT(*) AS c FROM component_param WHERE component_id = ?', [
        component.id,
      ]);

      const unresolvedCount = db.count(
        'SELECT COUNT(*) AS c FROM component_param WHERE component_id = ? AND is_default_resolved = 0',
        [component.id],
      );

      const raw = db.all<RawParam>(
        `SELECT code, type, default_repr, is_default_resolved, parent_group, multiple, values_repr
         FROM component_param WHERE component_id = ? ORDER BY position LIMIT ? OFFSET ?`,
        [component.id, limit, offset],
      );

      const params: ParamOut[] = raw.map((p) => {
        const out: ParamOut = { code: p.code };

        if (p.type) out.type = p.type;
        if (p.default_repr !== null) out.default = p.default_repr;
        if (p.is_default_resolved === 0) out.default_unresolved = true;
        if (p.parent_group) out.group = p.parent_group;
        if (p.multiple === 1) out.multiple = true;
        if (p.values_repr) out.values = p.values_repr;

        return out;
      });

      return envelope(
        db,
        {
          found: true,
          full_name: component.full_name,
          title: component.title,
          description: component.description,
          file: component.rel_path,
          // false означает, что $arComponentParameters строится динамически.
          // Компонент существует, но список параметров статически недоступен.
          params_resolved: component.params_resolved === 1,
          params,
          total,
          unresolved_count: unresolvedCount,
          truncated: offset + params.length < total,
        },
        { module: component.module, module_version: component.module_version },
      );
    },
  };
}
