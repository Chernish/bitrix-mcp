#!/usr/bin/env node

import { IndexDatabase, IndexMissingError, SchemaMismatchError } from './db/index.ts';
import { requireHttpToken } from './http-auth.ts';
import { createHttpMcpServer } from './http-server.ts';
import { resolveSnapshotFromProcess } from './snapshot-options.ts';
import type { ResolvedSnapshot } from './snapshots.ts';

function log(message: string): void {
  process.stderr.write(message + '\n');
}

async function main(): Promise<void> {
  const token = requireHttpToken(process.env.BITRIX_MCP_TOKEN);
  const host = process.env.BITRIX_MCP_HTTP_HOST ?? '127.0.0.1';
  const port = Number(process.env.BITRIX_MCP_HTTP_PORT ?? 3000);
  if (!Number.isInteger(port) || port < 0 || port > 65535) {
    throw new Error(`Некорректный BITRIX_MCP_HTTP_PORT: ${port}`);
  }

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
    log('⚠️  ' + db.unusableReason);
  }

  const server = createHttpMcpServer({ db, token, log });
  await new Promise<void>((resolve, reject) => {
    server.once('error', reject);
    server.listen(port, host, () => resolve());
  });

  const address = server.address();
  const actualPort = typeof address === 'object' && address !== null ? address.port : port;
  log(
    `bitrix-mcp-http: http://${host}:${actualPort}/mcp, ` +
    `SM_VERSION ${db.snapshot.sm_version ?? '—'}, снимок ${db.snapshotId}`,
  );

  let closing = false;
  const shutdown = () => {
    if (closing) return;
    closing = true;
    server.close(() => {
      db.close();
      process.exit(0);
    });
    server.closeIdleConnections();
    setTimeout(() => process.exit(1), 5_000).unref();
  };
  process.once('SIGINT', shutdown);
  process.once('SIGTERM', shutdown);
}

main().catch((error: unknown) => {
  log('НЕОЖИДАННАЯ ОШИБКА: ' + (error instanceof Error ? error.message : String(error)));
  process.exit(1);
});
