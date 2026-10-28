import type { IndexDatabase } from '../db/index.ts';
import { lookupSymbol, similarNames } from '../db/symbols.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

export function findSymbolTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_find_symbol',
    description:
      'Найти класс, интерфейс, трейт или перечисление в ядре Битрикса по имени. ' +
      'Принимает короткое имя (IblockTable) или полное (\\Bitrix\\Iblock\\IblockTable). ' +
      'Разрешает псевдонимы class_alias. Каждый результат содержит модуль-владелец и его версию. ' +
      'Если символа нет в проиндексированной сборке, возвращает found: false — без догадок.',
    inputSchema: {
      type: 'object',
      properties: {
        name: { type: 'string', description: 'Короткое или полное имя символа' },
        module: { type: 'string', description: 'Ограничить поиск модулем, например crm' },
        kind: {
          type: 'string',
          enum: ['class', 'interface', 'trait', 'enum'],
          description: 'Ограничить видом символа',
        },
        limit: { type: 'number', description: 'Максимум результатов, по умолчанию 10' },
      },
      required: ['name'],
    },
    handler: (args) => {
      const name = String(args.name ?? '');

      if (name.trim() === '') {
        return notFound(db, name, 'not_in_snapshot');
      }

      const { symbols, total, truncated } = lookupSymbol(db, name, {
        module: args.module ? String(args.module) : undefined,
        kind: args.kind ? String(args.kind) : undefined,
        limit: args.limit ? Number(args.limit) : undefined,
      });

      if (symbols.length === 0) {
        return notFound(db, name, 'not_in_snapshot', similarNames(db, name));
      }

      // Атрибуция в конверте ставится только когда владелец однозначен.
      // Иначе модуль и версия указаны на каждом элементе выдачи.
      const single = symbols.length === 1 ? symbols[0] : undefined;

      return envelope(
        db,
        { found: true, symbols, total, truncated },
        single ? { module: single.module, module_version: single.module_version } : {},
      );
    },
  };
}
