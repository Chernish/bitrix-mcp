import type { IndexDatabase } from './index.ts';

export interface MethodDeclaration {
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

interface TraitAdaptation {
  method_name_lower: string;
  alias: string | null;
  alias_lower: string | null;
  visibility: string | null;
  is_excluded: number;
}

export function resolveMethod(
  db: IndexDatabase,
  symbolId: number,
  methodName: string,
  visited: Set<number> = new Set(),
): MethodDeclaration | undefined {
  if (visited.has(symbolId)) return undefined;
  visited.add(symbolId);

  const needle = methodName.toLowerCase();
  const method = db.get<MethodDeclaration>(
    `SELECT me.signature, me.visibility, me.is_static, me.is_deprecated, me.line,
            s.fqn AS declared_in, f.rel_path AS file,
            m.code AS module, m.version AS module_version
     FROM method me
     JOIN symbol s ON s.id = me.symbol_id
     JOIN file f ON f.id = s.file_id
     JOIN module m ON m.id = s.module_id
     WHERE me.symbol_id = ? AND me.name_lower = ? LIMIT 1`,
    [symbolId, needle],
  );

  if (method) return method;

  const traits = db.all<{ trait_fqn_lower: string }>(
    'SELECT trait_fqn_lower FROM symbol_trait WHERE symbol_id = ? ORDER BY rowid',
    [symbolId],
  );

  for (const trait of traits) {
    const ownerId = findSymbolId(db, trait.trait_fqn_lower, 'trait');
    if (ownerId === undefined) continue;

    const adaptations = db.all<TraitAdaptation>(
      `SELECT method_name_lower, alias, alias_lower, visibility, is_excluded
       FROM symbol_trait_adaptation
       WHERE symbol_id = ? AND trait_fqn_lower = ? ORDER BY id`,
      [symbolId, trait.trait_fqn_lower],
    );
    const alias = adaptations.find(
      (item) => item.is_excluded === 0 && item.alias_lower === needle,
    );

    if (alias) {
      const aliased = resolveMethod(db, ownerId, alias.method_name_lower, visited);
      if (aliased) return adapt(aliased, alias.alias, alias.visibility);
    }

    const excluded = adaptations.some(
      (item) => item.is_excluded === 1 && item.method_name_lower === needle,
    );
    if (excluded) continue;

    const inherited = resolveMethod(db, ownerId, needle, visited);
    if (inherited) {
      const visibility = adaptations.find(
        (item) => item.is_excluded === 0 && item.alias === null &&
          item.method_name_lower === needle && item.visibility !== null,
      )?.visibility;
      return adapt(inherited, null, visibility ?? null);
    }
  }

  const parent = db.get<{ parent_fqn: string | null }>(
    'SELECT parent_fqn FROM symbol WHERE id = ?',
    [symbolId],
  );

  if (!parent?.parent_fqn) return undefined;

  const ownerId = findSymbolId(db, parent.parent_fqn);

  return ownerId === undefined ? undefined : resolveMethod(db, ownerId, needle, visited);
}

function findSymbolId(db: IndexDatabase, fqn: string, kind?: 'trait'): number | undefined {
  let needle = fqn.toLowerCase();
  const visited = new Set<string>();

  while (!visited.has(needle)) {
    visited.add(needle);
    const row = db.get<{ id: number }>(
      `SELECT id FROM symbol WHERE fqn_lower = ? ${kind ? 'AND kind = ?' : ''}
       ORDER BY id LIMIT 1`,
      kind ? [needle, kind] : [needle],
    );
    if (row) return row.id;

    const alias = db.get<{ target_fqn: string }>(
      'SELECT target_fqn FROM symbol_alias WHERE alias_fqn_lower = ? ORDER BY id LIMIT 1',
      [needle],
    );
    if (!alias) return undefined;
    needle = alias.target_fqn.toLowerCase();
  }

  return undefined;
}

function adapt(
  method: MethodDeclaration,
  alias: string | null,
  visibility: string | null,
): MethodDeclaration {
  let signature = method.signature;

  if (visibility !== null) {
    signature = signature.replace(
      /^((?:(?:abstract|final)\s+)*)(?:public|protected|private)/,
      `$1${visibility}`,
    );
  }
  if (alias !== null) {
    signature = signature.replace(
      /(function\s+&?)[a-zA-Z_][a-zA-Z0-9_]*(?=\s*\()/,
      `$1${alias}`,
    );
  }

  return { ...method, visibility: visibility ?? method.visibility, signature };
}
