import type { IndexDatabase } from './index.ts';

export interface ComponentGroup {
  module_id: number;
  root_id: number;
  full_name: string;
  namespace: string;
  name: string;
  title: string | null;
  description: string | null;
  params_resolved: number;
  module: string;
  module_version: string | null;
  rel_path: string;
  files: string;
  param_count: number;
  unresolved_count: number;
}

export const COMPONENT_GROUPS = `
  SELECT c.module_id, m.root_id, c.full_name, c.namespace, c.name,
         MAX(c.title) AS title, MAX(c.description) AS description,
         CASE WHEN SUM(CASE WHEN f.rel_path LIKE '%/.parameters.php' THEN 1 ELSE 0 END) = 0
              THEN 0 ELSE MIN(c.params_resolved) END AS params_resolved,
         m.code AS module, m.version AS module_version,
         COALESCE(
           MAX(CASE WHEN f.rel_path LIKE '%/.parameters.php' THEN f.rel_path END),
           MAX(f.rel_path)
         ) AS rel_path,
         GROUP_CONCAT(DISTINCT f.rel_path) AS files,
         COUNT(cp.id) AS param_count,
         SUM(CASE WHEN cp.is_default_resolved = 0 THEN 1 ELSE 0 END) AS unresolved_count
  FROM component c
  JOIN module m ON m.id = c.module_id
  JOIN file f ON f.id = c.file_id
  LEFT JOIN component_param cp ON cp.component_id = c.id
  GROUP BY m.root_id, c.module_id, c.full_name
`;

export function findComponentGroup(db: IndexDatabase, query: string): ComponentGroup | undefined {
  const rows = db.all<ComponentGroup>(
    `SELECT * FROM (${COMPONENT_GROUPS}) grouped
     WHERE full_name = ? OR name = ?
     ORDER BY (full_name = ?) DESC, root_id, module_id LIMIT 2`,
    [query, query, query],
  );

  return rows.length === 1 ? rows[0] : undefined;
}

export function componentGroupIsAmbiguous(db: IndexDatabase, query: string): boolean {
  return db.count(
    `SELECT COUNT(*) AS c FROM (${COMPONENT_GROUPS}) grouped WHERE full_name = ? OR name = ?`,
    [query, query],
  ) > 1;
}
