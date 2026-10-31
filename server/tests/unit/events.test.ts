import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import Database from 'better-sqlite3';

import { IndexDatabase, SUPPORTED_SCHEMA_VERSION } from '../../src/db/index.ts';
import { listEventsTool } from '../../src/tools/list-events.ts';

describe('Владелец события и модуль подписки', () => {
  let directory: string;
  let db: IndexDatabase;

  before(() => {
    directory = mkdtempSync(join(tmpdir(), 'bitrix-mcp-events-'));
    const writable = new Database(join(directory, 'index.sqlite'));
    writable.exec(`
      CREATE TABLE snapshot (
        id INTEGER PRIMARY KEY, sm_version TEXT, sm_version_date TEXT, indexed_at TEXT,
        indexer_version TEXT, schema_version INTEGER, file_count INTEGER, total_bytes INTEGER,
        parse_error_count INTEGER, short_open_tag_enabled INTEGER, docs_crawled_at TEXT,
        docs_source TEXT, docs_source_revision TEXT
      );
      INSERT INTO snapshot VALUES
        (1, '26.0.0', NULL, '2026-01-01T00:00:00Z', 'test', ${SUPPORTED_SCHEMA_VERSION}, 0, 0, 0, 1, NULL, NULL, NULL);
      CREATE TABLE index_root (id INTEGER PRIMARY KEY, path TEXT, label TEXT, is_core INTEGER);
      CREATE TABLE module (
        id INTEGER PRIMARY KEY, root_id INTEGER, code TEXT, version TEXT,
        version_date TEXT, version_source TEXT, rel_path TEXT
      );
      INSERT INTO module VALUES
        (1, NULL, 'testmod', '42.7.0', NULL, 'module_file', 'modules/testmod'),
        (2, NULL, 'main', '26.0.0', NULL, 'sm_version', 'modules/main');
      CREATE TABLE file (id INTEGER PRIMARY KEY, root_id INTEGER, module_id INTEGER, rel_path TEXT);
      INSERT INTO file VALUES
        (1, NULL, 1, 'modules/testmod/lib/handler.php'),
        (2, NULL, 2, 'modules/main/lib/event.php');
      CREATE TABLE event (
        id INTEGER PRIMARY KEY, module_id INTEGER, file_id INTEGER, owner_module TEXT,
        name TEXT, name_lower TEXT, kind TEXT, call_style TEXT, params_repr TEXT, line INTEGER
      );
      INSERT INTO event VALUES
        (1, 1, 1, 'main', 'OnAudit', 'onaudit', 'register', 'EventManager', NULL, 10),
        (2, 2, 2, 'main', 'OnAudit', 'onaudit', 'send', 'new Event', NULL, 20);
    `);
    writable.close();
    db = new IndexDatabase(join(directory, 'index.sqlite'));
  });

  after(() => {
    db?.close();
    rmSync(directory, { recursive: true, force: true });
  });

  test('подписка testmod на main возвращается у testmod с его версией', () => {
    const response = listEventsTool(db).handler({ module: 'testmod', kind: 'register' }) as {
      result: { total: number; events: Array<{ name: string; file: string }> };
      provenance: { module: string; module_version: string };
    };

    assert.equal(response.result.total, 1);
    assert.deepEqual(response.result.events, [{
      name: 'OnAudit',
      occurrences: 1,
      file: 'modules/testmod/lib/handler.php',
      line: 10,
    }]);
    assert.equal(response.provenance.module, 'testmod');
    assert.equal(response.provenance.module_version, '42.7.0');
  });

  test('отправка события остаётся у owner main', () => {
    const response = listEventsTool(db).handler({ module: 'main', kind: 'send' }) as {
      result: { total: number; events: Array<{ name: string; file: string }> };
    };

    assert.equal(response.result.total, 1);
    assert.equal(response.result.events[0]?.file, 'modules/main/lib/event.php');
  });
});
