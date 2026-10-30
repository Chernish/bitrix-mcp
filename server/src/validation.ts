import type { ToolDefinition } from './types.ts';

const LIMIT_CAPS: Record<string, number> = {
  bitrix_find_symbol: 50,
  bitrix_get_class: 100,
  bitrix_get_orm_entity: 100,
  bitrix_list_events: 100,
  bitrix_get_component: 100,
  bitrix_list_rest_methods: 100,
  bitrix_search_docs: 10,
  bitrix_search_code: 10,
  bitrix_list_modules: 30,
  bitrix_list_module_api: 25,
  bitrix_list_components: 25,
  bitrix_get_class_constants: 25,
  bitrix_find_implementations: 25,
  bitrix_list_data_managers: 20,
  bitrix_find_events: 20,
  bitrix_find_callable: 20,
  bitrix_find_usages: 20,
  bitrix_find_namespace: 25,
};

export function constrainToolSchema(tool: ToolDefinition): ToolDefinition {
  const current = tool.inputSchema.properties;
  if (!isRecord(current)) {
    return { ...tool, inputSchema: { ...tool.inputSchema, additionalProperties: false } };
  }

  const properties = Object.fromEntries(
    Object.entries(current).map(([name, value]) => {
      if (!isRecord(value)) return [name, value];
      let schema = value;

      if (name === 'limit') {
        schema = { ...schema, type: 'integer', minimum: 1, maximum: LIMIT_CAPS[tool.name] ?? 100 };
      } else if (name === 'offset') {
        schema = { ...schema, type: 'integer', minimum: 0 };
      } else if (name === 'max_depth') {
        schema = { ...schema, type: 'integer', minimum: 1, maximum: 20 };
      } else if (name === 'dependency_limit') {
        schema = { ...schema, type: 'integer', minimum: 1, maximum: 25 };
      } else if (name === 'length') {
        schema = { ...schema, type: 'integer', minimum: 200, maximum: 1200 };
      }

      return [name, schema];
    }),
  );

  return {
    ...tool,
    inputSchema: { ...tool.inputSchema, additionalProperties: false, properties },
  };
}

export function validateToolArguments(schema: Record<string, unknown>, args: unknown): string | null {
  if (!isRecord(args)) return 'ожидается объект аргументов';

  const properties = isRecord(schema.properties) ? schema.properties : {};
  const required = Array.isArray(schema.required) ? schema.required : [];

  for (const name of required) {
    if (typeof name === 'string' && !Object.hasOwn(args, name)) {
      return `отсутствует обязательное поле "${name}"`;
    }
  }

  if (schema.additionalProperties === false) {
    for (const name of Object.keys(args)) {
      if (!Object.hasOwn(properties, name)) return `неизвестное поле "${name}"`;
    }
  }

  for (const [name, value] of Object.entries(args)) {
    const rule = properties[name];
    if (!isRecord(rule)) continue;

    if (!matchesType(rule.type, value)) {
      return `поле "${name}" должно иметь тип ${String(rule.type)}`;
    }

    if (Array.isArray(rule.enum) && !rule.enum.some((candidate) => candidate === value)) {
      return `поле "${name}" содержит недопустимое значение`;
    }

    if (typeof value === 'number') {
      if (typeof rule.minimum === 'number' && value < rule.minimum) {
        return `поле "${name}" должно быть не меньше ${rule.minimum}`;
      }
      if (typeof rule.maximum === 'number' && value > rule.maximum) {
        return `поле "${name}" должно быть не больше ${rule.maximum}`;
      }
    }
  }

  return null;
}

function matchesType(type: unknown, value: unknown): boolean {
  if (type === undefined) return true;
  if (type === 'string') return typeof value === 'string';
  if (type === 'boolean') return typeof value === 'boolean';
  if (type === 'number') return typeof value === 'number' && Number.isFinite(value);
  if (type === 'integer') return typeof value === 'number' && Number.isInteger(value);
  if (type === 'object') return isRecord(value);
  if (type === 'array') return Array.isArray(value);

  return true;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
