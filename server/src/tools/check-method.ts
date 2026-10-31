import type { IndexDatabase } from '../db/index.ts';
import { resolveMethod } from '../db/methods.ts';
import { lookupSymbol, similarNames } from '../db/symbols.ts';
import { envelope } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

export function checkMethodTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_check_method',
    description:
      'Проверить, существует ли метод у класса в проиндексированной сборке Битрикса. ' +
      'Учитывает наследование и трейты. Возвращает сигнатуру и фактическое место объявления. ' +
      'Различает «нет такого класса» и «нет такого метода в существующем классе».',
    inputSchema: {
      type: 'object',
      properties: {
        class: { type: 'string', description: 'Имя класса, полное или короткое' },
        method: { type: 'string', description: 'Имя метода' },
      },
      required: ['class', 'method'],
    },
    handler: (args) => {
      const className = String(args.class ?? '');
      const methodName = String(args.method ?? '');
      const symbol = lookupSymbol(db, className, { limit: 1 }).symbols[0];

      if (!symbol) {
        const result: Record<string, unknown> = {
          exists: false,
          class_exists: false,
          query: { class: className, method: methodName },
        };
        const suggestions = similarNames(db, className);

        if (suggestions.length > 0) {
          result.did_you_mean = { guess: true, names: suggestions };
        }

        return envelope(db, result);
      }

      const row = db.get<{ id: number }>('SELECT id FROM symbol WHERE fqn = ? LIMIT 1', [
        symbol.canonical_fqn,
      ]);
      const method = row ? resolveMethod(db, row.id, methodName, new Set()) : undefined;

      return envelope(
        db,
        {
          exists: method !== undefined,
          class_exists: true,
          fqn: symbol.fqn,
          canonical_fqn: symbol.canonical_fqn,
          declared_in: method?.declared_in ?? null,
          signature: method?.signature ?? null,
          visibility: method?.visibility ?? null,
          is_static: method ? method.is_static === 1 : null,
          is_deprecated: method ? method.is_deprecated === 1 : null,
          file: method?.file ?? symbol.file,
          line: method?.line ?? symbol.line,
        },
        method
          ? { module: method.module, module_version: method.module_version }
          : { module: symbol.module, module_version: symbol.module_version },
      );
    },
  };
}
