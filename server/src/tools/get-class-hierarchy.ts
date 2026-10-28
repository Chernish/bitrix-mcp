import type { IndexDatabase } from '../db/index.ts';
import { lookupSymbol } from '../db/symbols.ts';
import { envelope, notFound } from '../envelope.ts';
import type { ToolDefinition } from '../types.ts';

interface HierarchyNode {
  fqn: string;
  kind: string;
  module: string;
  module_version: string | null;
  version_unknown: boolean;
  parent_fqn: string | null;
  file: string;
  line: number;
}

export function getClassHierarchyTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_get_class_hierarchy',
    description:
      'Цепочка наследования класса и его прямые интерфейсы. Каждое звено несёт ' +
      'собственную версию модуля; ненайденный родитель помечается явно.',
    inputSchema: {
      type: 'object',
      properties: {
        class: { type: 'string' },
        max_depth: { type: 'number', description: 'Максимум 20, по умолчанию 10' },
      },
      required: ['class'],
    },
    handler: (args) => {
      const query = String(args.class ?? '');
      const start = lookupSymbol(db, query, { limit: 1 }).symbols[0];
      if (!start) return notFound(db, query);

      const maxDepth = Math.min(Math.max(Number(args.max_depth ?? 10), 1), 20);
      const chain: HierarchyNode[] = [];
      const seen = new Set<string>();
      let current = start.canonical_fqn;
      let unresolvedParent: string | null = null;

      while (current && chain.length < maxDepth && !seen.has(current.toLowerCase())) {
        seen.add(current.toLowerCase());
        const found = lookupSymbol(db, current, { limit: 1 }).symbols[0];
        if (!found) {
          unresolvedParent = current;
          break;
        }
        chain.push({
          fqn: found.canonical_fqn,
          kind: found.kind,
          module: found.module,
          module_version: found.module_version,
          version_unknown: found.module_version === null,
          parent_fqn: found.parent_fqn,
          file: found.file,
          line: found.line,
        });
        current = found.parent_fqn ?? '';
      }

      const interfaces = start.interfaces.map((fqn) => {
        const found = lookupSymbol(db, fqn, { limit: 1 }).symbols[0];
        return found
          ? {
              fqn: found.canonical_fqn,
              module: found.module,
              module_version: found.module_version,
              version_unknown: found.module_version === null,
              file: found.file,
              line: found.line,
            }
          : { fqn, unresolved: true as const };
      });

      return envelope(db, {
        found: true,
        class: start.canonical_fqn,
        chain,
        interfaces,
        unresolved_parent: unresolvedParent,
        truncated: current !== '' && chain.length >= maxDepth,
      });
    },
  };
}
