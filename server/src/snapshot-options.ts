import { resolveSnapshotIndex } from './snapshots.ts';
import type { ResolvedSnapshot } from './snapshots.ts';

export function resolveSnapshotFromProcess(): ResolvedSnapshot {
  const args = process.argv.slice(2);
  const indexArg = args.find((arg) => arg.startsWith('--index='));
  const snapshotArg = args.find((arg) => arg.startsWith('--snapshot='));
  const indexPath = indexArg?.slice('--index='.length)
    ?? process.env.BITRIX_MCP_INDEX
    ?? './index.sqlite';
  const snapshotId = snapshotArg?.slice('--snapshot='.length)
    ?? process.env.BITRIX_MCP_SNAPSHOT;

  return resolveSnapshotIndex(indexPath, snapshotId);
}
