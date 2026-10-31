import type { IndexDatabase } from '../db/index.ts';
import { componentGroupIsAmbiguous, findComponentGroup } from '../db/components.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

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

      const component = findComponentGroup(db, query);

      if (!component) {
        return notFound(
          db,
          query,
          componentGroupIsAmbiguous(db, query) ? 'ambiguous' : 'not_in_snapshot',
        );
      }

      const total = component.param_count;
      const unresolvedCount = component.unresolved_count;
      const raw = db.all<RawParam>(
        `SELECT cp.code, cp.type, cp.default_repr, cp.is_default_resolved,
                cp.parent_group, cp.multiple, cp.values_repr
         FROM component_param cp
         JOIN component c ON c.id = cp.component_id
         WHERE c.module_id = ? AND c.full_name = ?
         ORDER BY cp.position, c.id LIMIT ? OFFSET ?`,
        [component.module_id, component.full_name, limit, offset],
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
          files: component.files.split(',').sort(),
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
