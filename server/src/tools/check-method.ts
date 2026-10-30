import type { IndexDatabase } from '../db/index.ts';
import { lookupSymbol, similarNames } from '../db/symbols.ts';
import { envelope } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

interface MethodDeclaration {
  signature: string;
  visibility: string;
  is_static: number;
  is_deprecated: number;
  line: number;
  declared_in: string;
  file: string;
  module: string;
  module_version: string | null;
}

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

function resolveMethod(
  db: IndexDatabase,
  symbolId: number,
  methodName: string,
  visited: Set<number>,
): MethodDeclaration | undefined {
  if (visited.has(symbolId)) return undefined;
  visited.add(symbolId);

  const method = db.get<MethodDeclaration>(
    `SELECT me.signature, me.visibility, me.is_static, me.is_deprecated, me.line,
            s.fqn AS declared_in, f.rel_path AS file,
            m.code AS module, m.version AS module_version
     FROM method me
     JOIN symbol s ON s.id = me.symbol_id
     JOIN file f ON f.id = s.file_id
     JOIN module m ON m.id = s.module_id
     WHERE me.symbol_id = ? AND me.name_lower = ? LIMIT 1`,
    [symbolId, methodName.toLowerCase()],
  );

  if (method) return method;

  const traits = db.all<{ trait_fqn_lower: string }>(
    'SELECT trait_fqn_lower FROM symbol_trait WHERE symbol_id = ? ORDER BY rowid',
    [symbolId],
  );

  for (const trait of traits) {
    const owner = db.get<{ id: number }>(
      "SELECT id FROM symbol WHERE fqn_lower = ? AND kind = 'trait' ORDER BY id LIMIT 1",
      [trait.trait_fqn_lower],
    );
    const inherited = owner
      ? resolveMethod(db, owner.id, methodName, visited)
      : undefined;

    if (inherited) return inherited;
  }

  const parent = db.get<{ parent_fqn: string | null }>(
    'SELECT parent_fqn FROM symbol WHERE id = ?',
    [symbolId],
  );

  if (!parent?.parent_fqn) return undefined;

  const owner = db.get<{ id: number }>(
    'SELECT id FROM symbol WHERE fqn_lower = ? ORDER BY id LIMIT 1',
    [parent.parent_fqn.toLowerCase()],
  );

  return owner ? resolveMethod(db, owner.id, methodName, visited) : undefined;
}
