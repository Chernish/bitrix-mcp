import { createHash } from 'node:crypto';
import { closeSync, existsSync, openSync, readFileSync, readSync } from 'node:fs';
import { join } from 'node:path';

import { IndexMissingError } from './db/index.ts';
import type { SnapshotRole } from './types.ts';

export interface SnapshotMetadata {
  id: string;
  file: string;
  sm_version: string | null;
  sm_version_date: string | null;
  indexed_at: string;
  core_root: string | null;
  docs_crawled_at: string | null;
  docs_source: string | null;
  docs_source_revision: string | null;
  size_bytes: number;
  sha256: string;
}

export interface ResolvedSnapshot {
  path: string;
  snapshotId: string | null;
  snapshotRole: SnapshotRole;
}

export class SnapshotCatalogError extends IndexMissingError {}

export function resolveSnapshotIndex(indexPath: string, requestedId?: string): ResolvedSnapshot {
  const snapshots = readCatalog(indexPath);
  if (snapshots.length === 0 && !requestedId) {
    return { path: indexPath, snapshotId: null, snapshotRole: 'direct' };
  }

  if (requestedId !== undefined && requestedId !== '') {
    if (!/^[A-Za-z0-9._-]+$/.test(requestedId)) {
      throw new SnapshotCatalogError(`Некорректный ID снимка: ${requestedId}`);
    }

    const metadata = snapshots.find((item) => item.id === requestedId);
    if (!metadata) {
      throw new SnapshotCatalogError(`Снимок не найден: ${requestedId}`);
    }

    const path = join(`${indexPath}.snapshots`, metadata.file);
    if (!existsSync(path) || hashFile(path) !== metadata.sha256) {
      throw new SnapshotCatalogError(`Содержимое снимка ${requestedId} не совпадает с каталогом`);
    }

    return { path, snapshotId: metadata.id, snapshotRole: 'archive' };
  }

  if (existsSync(indexPath)) {
    const hash = hashFile(indexPath);
    const activeId = readActiveId(indexPath);
    const pointed = activeId
      ? snapshots.find((item) => item.id === activeId && item.sha256 === hash)
      : undefined;
    const active = pointed ?? snapshots.findLast((item) => item.sha256 === hash);
    if (active) {
      return { path: indexPath, snapshotId: active.id, snapshotRole: 'active' };
    }
  }

  return { path: indexPath, snapshotId: null, snapshotRole: 'direct' };
}

export function hashFile(path: string): string {
  const hash = createHash('sha256');
  const fd = openSync(path, 'r');
  const buffer = Buffer.alloc(1024 * 1024);

  try {
    let read = 0;
    do {
      read = readSync(fd, buffer, 0, buffer.length, null);
      if (read > 0) hash.update(buffer.subarray(0, read));
    } while (read > 0);
  } finally {
    closeSync(fd);
  }

  return hash.digest('hex');
}

function readActiveId(indexPath: string): string | undefined {
  const path = `${indexPath}.snapshots/active.json`;
  if (!existsSync(path)) return undefined;

  let data: unknown;
  try {
    data = JSON.parse(readFileSync(path, 'utf8'));
  } catch {
    throw new SnapshotCatalogError(`Повреждён указатель активного снимка: ${path}`);
  }

  if (
    typeof data !== 'object' || data === null ||
    !('id' in data) || typeof data.id !== 'string' || !/^[A-Za-z0-9._-]+$/.test(data.id)
  ) {
    throw new SnapshotCatalogError(`Повреждён указатель активного снимка: ${path}`);
  }

  return data.id;
}

function readCatalog(indexPath: string): SnapshotMetadata[] {
  const path = `${indexPath}.snapshots/catalog.json`;
  if (!existsSync(path)) return [];

  let data: unknown;
  try {
    data = JSON.parse(readFileSync(path, 'utf8'));
  } catch {
    throw new SnapshotCatalogError(`Не удалось прочитать каталог снимков: ${path}`);
  }

  if (
    typeof data !== 'object' || data === null ||
    !('version' in data) || data.version !== 1 ||
    !('snapshots' in data) || !Array.isArray(data.snapshots)
  ) {
    throw new SnapshotCatalogError(`Повреждён каталог снимков: ${path}`);
  }

  return data.snapshots.map((row) => normalizeMetadata(row, path));
}

function normalizeMetadata(value: unknown, catalogPath: string): SnapshotMetadata {
  if (typeof value !== 'object' || value === null) invalid(catalogPath);
  const row = value as Record<string, unknown>;
  const requiredStrings = ['id', 'file', 'indexed_at', 'sha256'];
  for (const key of requiredStrings) {
    if (typeof row[key] !== 'string') invalid(catalogPath);
  }

  if (
    !/^[A-Za-z0-9._-]+$/.test(row.id as string) ||
    row.file !== `${row.id}.sqlite` ||
    !/^[0-9a-f]{64}$/.test(row.sha256 as string) ||
    !Number.isInteger(row.size_bytes)
  ) {
    invalid(catalogPath);
  }

  const nullable = [
    'sm_version', 'sm_version_date', 'core_root',
    'docs_crawled_at', 'docs_source', 'docs_source_revision',
  ];
  for (const key of nullable) {
    if (row[key] !== null && typeof row[key] !== 'string') invalid(catalogPath);
  }

  return {
    id: row.id as string,
    file: row.file as string,
    sm_version: row.sm_version as string | null,
    sm_version_date: row.sm_version_date as string | null,
    indexed_at: row.indexed_at as string,
    core_root: row.core_root as string | null,
    docs_crawled_at: row.docs_crawled_at as string | null,
    docs_source: row.docs_source as string | null,
    docs_source_revision: row.docs_source_revision as string | null,
    size_bytes: row.size_bytes as number,
    sha256: row.sha256 as string,
  };
}

function invalid(path: string): never {
  throw new SnapshotCatalogError(`Повреждён каталог снимков: ${path}`);
}
