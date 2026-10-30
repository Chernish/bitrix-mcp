import type { IndexDatabase } from '../db/index.ts';
import { envelope } from '../envelope.ts';
import { checkDeepStaleness } from '../staleness.ts';
import type { ToolDefinition } from '../types.ts';

/**
 * Диагностика состояния индекса.
 *
 * Не заменяет флаг `stale` в остальных ответах, а дополняет его подробностями.
 * Полагаться только на этот инструмент нельзя: агент, не спросивший о состоянии
 * индекса, обязан узнать об устаревании из самого факта (Принцип II).
 */
export function indexStatusTool(db: IndexDatabase): ToolDefinition {
  return {
    name: 'bitrix_index_status',
    description:
      'Состояние индекса ядра Битрикса: версия сборки, момент индексации, счётчики, ' +
      'ошибки разбора, признак устаревания и признак непригодности индекса.',
    inputSchema: {
      type: 'object',
      properties: {
        deep: {
          type: 'boolean',
          description: 'Включить карту версий всех модулей',
        },
      },
    },
    handler: (args) => {
      const s = db.snapshot;

      const counts = {
        modules: db.count('SELECT COUNT(*) AS c FROM module'),
        files: db.count('SELECT COUNT(*) AS c FROM file'),
        symbols: db.count('SELECT COUNT(*) AS c FROM symbol'),
        methods: db.count('SELECT COUNT(*) AS c FROM method'),
        functions: db.count('SELECT COUNT(*) AS c FROM global_function'),
        class_constants: db.count('SELECT COUNT(*) AS c FROM class_constant'),
        usages: db.count('SELECT COUNT(*) AS c FROM usage_reference'),
        aliases: db.count('SELECT COUNT(*) AS c FROM symbol_alias'),
        short_open_tag_files: db.count(
          'SELECT COUNT(*) AS c FROM file WHERE had_short_open_tag = 1',
        ),
      };

      const payload: Record<string, unknown> = {
        core_root: db.coreRoot(),
        index_path: db.path,
        sm_version: s.sm_version,
        snapshot_id: db.snapshotId,
        snapshot_role: db.snapshotRole,
        sm_version_date: s.sm_version_date,
        indexed_at: s.indexed_at,
        indexer_version: s.indexer_version,
        schema_version: s.schema_version,
        docs: {
          indexed_at: s.docs_crawled_at,
          source: s.docs_source,
          revision: s.docs_source_revision,
          pages: db.count('SELECT COUNT(*) AS c FROM doc_page'),
        },
        short_open_tag_enabled: s.short_open_tag_enabled === 1,
        counts,
        parse_errors: {
          count: s.parse_error_count,
          share: counts.files > 0 ? s.parse_error_count / counts.files : 0,
          sample: db.all<{ rel_path: string; message: string }>(
            `SELECT f.rel_path, p.message FROM parse_error p
             JOIN file f ON f.id = p.file_id LIMIT 5`,
          ),
        },
      };

      const deep = args.deep === true ? checkDeepStaleness(db) : undefined;

      if (deep) {
        payload.deep_check = {
          modules_checked: deep.checked,
          drifted: deep.drifted,
        };
        payload.module_versions = Object.fromEntries(
          db
            .all<{ code: string; version: string | null }>(
              'SELECT code, version FROM module ORDER BY code',
            )
            .map((m) => [m.code, m.version]),
        );
        payload.modules_without_version = db
          .all<{ code: string }>('SELECT code FROM module WHERE version IS NULL ORDER BY code')
          .map((m) => m.code);
      }

      return envelope(db, payload, {}, deep);
    },
  };
}
