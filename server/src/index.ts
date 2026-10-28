#!/usr/bin/env node
/**
 * MCP-сервер: версионно-привязанный доступ к API ядра 1С-Битрикс.
 *
 * Транспорт stdio. КРИТИЧНО: stdout принадлежит исключительно потоку сообщений
 * протокола. Любая посторонняя строка в stdout ломает соединение, поэтому вся
 * диагностика идёт в stderr (research.md, R-012).
 */

import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';

import { IndexDatabase, IndexMissingError, SchemaMismatchError } from './db/index.ts';
import { createMcpInstance } from './mcp.ts';
import { resolveSnapshotFromProcess } from './snapshot-options.ts';
import type { ResolvedSnapshot } from './snapshots.ts';

function log(message: string): void {
  process.stderr.write(message + '\n');
}

async function main(): Promise<void> {
  let resolution: ResolvedSnapshot;
  let db: IndexDatabase;

  try {
    resolution = resolveSnapshotFromProcess();
    db = new IndexDatabase(resolution.path, resolution);
  } catch (error) {
    if (error instanceof IndexMissingError || error instanceof SchemaMismatchError) {
      log(error.message);
      process.exit(error instanceof SchemaMismatchError ? 5 : 1);
    }

    throw error;
  }

  if (db.isUnusable) {
    // Не отказ: индекс всё-таки содержит данные, и агенту лучше получить
    // их с явным предупреждением в каждом ответе, чем не получить ничего.
    // Предупреждение уходит в provenance.index_unusable, а не только сюда.
    log('⚠️  ' + db.unusableReason);
  }

  const { server, toolCount } = createMcpInstance(db, log);

  log(
    `bitrix-mcp: индекс ${resolution.path}, SM_VERSION ${db.snapshot.sm_version ?? '—'}, ` +
      `снимок ${db.snapshotId}, инструментов: ${toolCount}`,
  );

  const transport = new StdioServerTransport();
  await server.connect(transport);
}

main().catch((error: unknown) => {
  log('НЕОЖИДАННАЯ ОШИБКА: ' + (error instanceof Error ? error.stack : String(error)));
  process.exit(1);
});
