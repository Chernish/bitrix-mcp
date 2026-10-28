import type { IndexDatabase } from '../db/index.ts';
import { lookupSymbol } from '../db/symbols.ts';
import { envelope } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

/**
 * Прямая проверка существования — самый дешёвый способ погасить галлюцинацию.
 *
 * Различение `exists` и `class_exists` даёт агенту точный диагноз: неверен
 * класс или неверен метод в верном классе. Это разные ошибки с разными
 * исправлениями, и склеивать их в один флаг значит терять полезный сигнал.
 */
export function checkMethodTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_check_method',
    description:
      'Проверить, существует ли метод у класса в проиндексированной сборке Битрикса. ' +
      'Возвращает однозначный ответ с сигнатурой, модулем и его версией. ' +
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

      const { symbols } = lookupSymbol(db, className, { limit: 1 });
      const symbol = symbols[0];

      if (!symbol) {
        return envelope(db, {
          exists: false,
          class_exists: false,
          query: { class: className, method: methodName },
        });
      }

      const row = db.get<{ id: number }>('SELECT id FROM symbol WHERE fqn = ? LIMIT 1', [
        symbol.canonical_fqn,
      ]);

      const method = row
        ? db.get<{
            signature: string;
            visibility: string;
            is_static: number;
            is_deprecated: number;
            line: number;
          }>(
            `SELECT signature, visibility, is_static, is_deprecated, line
             FROM method WHERE symbol_id = ? AND name_lower = ? LIMIT 1`,
            [row.id, methodName.toLowerCase()],
          )
        : undefined;

      return envelope(
        db,
        {
          exists: method !== undefined,
          class_exists: true,
          fqn: symbol.fqn,
          canonical_fqn: symbol.canonical_fqn,
          signature: method?.signature ?? null,
          visibility: method?.visibility ?? null,
          is_static: method ? method.is_static === 1 : null,
          is_deprecated: method ? method.is_deprecated === 1 : null,
          file: symbol.file,
          line: method?.line ?? symbol.line,
        },
        { module: symbol.module, module_version: symbol.module_version },
      );
    },
  };
}
