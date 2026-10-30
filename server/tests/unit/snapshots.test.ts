import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { copyFileSync, mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import Database from 'better-sqlite3';

import { IndexDatabase, SUPPORTED_SCHEMA_VERSION } from '../../src/db/index.ts';
import { buildProvenance } from '../../src/envelope.ts';
import { hashFile, resolveSnapshotIndex, SnapshotCatalogError } from '../../src/snapshots.ts';
import type { SnapshotMetadata } from '../../src/snapshots.ts';
import { resetStalenessCache } from '../../src/staleness.ts';

describe('Версионные снимки индекса', () => {
  let root: string;
  let activePath: string;

  before(() => {
    root = join(tmpdir(), `bitrix-mcp-snapshots-${process.pid}-${Date.now()}`);
    activePath = join(root, 'index.sqlite');
    mkdirSync(root, { recursive: true });
  });

  after(() => rmSync(root, { recursive: true, force: true }));

  function reset(): void {
    rmSync(activePath, { force: true });
    rmSync(`${activePath}.snapshots`, { recursive: true, force: true });
    mkdirSync(`${activePath}.snapshots`, { recursive: true });
    resetStalenessCache();
  }

  function createIndex(path: string, version: string, indexedAt: string): void {
    mkdirSync(dirname(path), { recursive: true });
    const db = new Database(path);
    db.exec(`
      CREATE TABLE snapshot (
        id INTEGER PRIMARY KEY,
        sm_version TEXT,
        sm_version_date TEXT,
        indexed_at TEXT NOT NULL,
        indexer_version TEXT NOT NULL,
        schema_version INTEGER NOT NULL,
        file_count INTEGER NOT NULL,
        total_bytes INTEGER NOT NULL,
        parse_error_count INTEGER NOT NULL,
        short_open_tag_enabled INTEGER NOT NULL,
        docs_crawled_at TEXT,
        docs_source TEXT,
        docs_source_revision TEXT
      );
      CREATE TABLE index_root (id INTEGER PRIMARY KEY, path TEXT, label TEXT, is_core INTEGER);
      CREATE TABLE module (code TEXT, version TEXT, rel_path TEXT);
    `);
    db.prepare(`
      INSERT INTO snapshot VALUES (
        1, ?, NULL, ?, 'test', ?, 0, 0, 0, 1, NULL, 'git', ?
      )
    `).run(version, indexedAt, SUPPORTED_SCHEMA_VERSION, `docs-${version}`);
    db.close();
  }

  function metadata(id: string, path: string, version: string, indexedAt: string): SnapshotMetadata {
    return {
      id,
      file: `${id}.sqlite`,
      sm_version: version,
      sm_version_date: null,
      indexed_at: indexedAt,
      core_root: null,
      docs_crawled_at: null,
      docs_source: 'git',
      docs_source_revision: `docs-${version}`,
      size_bytes: statSync(path).size,
      sha256: hashFile(path),
    };
  }

  function writeCatalog(snapshots: SnapshotMetadata[], activeId?: string): void {
    const directory = `${activePath}.snapshots`;
    mkdirSync(directory, { recursive: true });
    writeFileSync(join(directory, 'catalog.json'), JSON.stringify({ version: 1, snapshots }));
    if (activeId) writeFileSync(join(directory, 'active.json'), JSON.stringify({ id: activeId }));
  }

  test('active и archive возвращают один ID без смешивания версий', () => {
    reset();
    createIndex(activePath, '25.0.0', '2026-01-01T00:00:00+00:00');
    const archivePath = `${activePath}.snapshots/core-a.sqlite`;
    copyFileSync(activePath, archivePath);
    const a = metadata('core-a', archivePath, '25.0.0', '2026-01-01T00:00:00+00:00');
    writeCatalog([a], a.id);

    const active = resolveSnapshotIndex(activePath);
    assert.equal(active.snapshotRole, 'active');
    assert.equal(active.snapshotId, 'core-a');
    const archive = resolveSnapshotIndex(activePath, 'core-a');
    assert.equal(archive.snapshotRole, 'archive');
    assert.equal(archive.snapshotId, 'core-a');

    const db = new IndexDatabase(archive.path, archive);
    try {
      const provenance = buildProvenance(db);
      assert.equal(provenance.sm_version, '25.0.0');
      assert.equal(provenance.snapshot_id, 'core-a');
      assert.equal(provenance.snapshot_role, 'archive');
    } finally {
      db.close();
    }
  });

  test('active.json различает побайтово одинаковые снимки', () => {
    reset();
    createIndex(activePath, '25.0.0', '2026-01-01T00:00:00+00:00');
    const aPath = `${activePath}.snapshots/core-a.sqlite`;
    const bPath = `${activePath}.snapshots/core-b.sqlite`;
    copyFileSync(activePath, aPath);
    copyFileSync(activePath, bPath);
    const a = metadata('core-a', aPath, '25.0.0', '2026-01-01T00:00:00+00:00');
    const b = metadata('core-b', bPath, '25.0.0', '2026-01-01T00:00:00+00:00');
    writeCatalog([a, b], 'core-a');

    assert.equal(resolveSnapshotIndex(activePath).snapshotId, 'core-a');
  });

  test('неизвестный ID и подмена содержимого отклоняются', () => {
    reset();
    createIndex(activePath, '25.0.0', '2026-01-01T00:00:00+00:00');
    const archivePath = `${activePath}.snapshots/core-a.sqlite`;
    copyFileSync(activePath, archivePath);
    const a = metadata('core-a', archivePath, '25.0.0', '2026-01-01T00:00:00+00:00');
    writeCatalog([a], a.id);

    assert.throws(() => resolveSnapshotIndex(activePath, 'missing'), SnapshotCatalogError);
    writeFileSync(archivePath, 'tampered');
    assert.throws(() => resolveSnapshotIndex(activePath, 'core-a'), SnapshotCatalogError);
  });

  test('индекс вне каталога получает direct provenance', () => {
    reset();
    createIndex(activePath, '26.0.0', '2026-02-02T00:00:00+00:00');
    const resolution = resolveSnapshotIndex(activePath);
    assert.equal(resolution.snapshotRole, 'direct');
    assert.equal(resolution.snapshotId, null);

    const db = new IndexDatabase(resolution.path, resolution);
    try {
      const provenance = buildProvenance(db);
      assert.equal(provenance.snapshot_role, 'direct');
      assert.equal(provenance.snapshot_id, 'direct:26.0.0:2026-02-02T00:00:00+00:00');
      assert.ok(readFileSync(activePath).length > 0);
    } finally {
      db.close();
    }
  });
});
