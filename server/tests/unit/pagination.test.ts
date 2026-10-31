import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import Database from 'better-sqlite3';

import { IndexDatabase, SUPPORTED_SCHEMA_VERSION } from '../../src/db/index.ts';
import { findNamespaceTool } from '../../src/tools/find-namespace.ts';
import { findCallableTool } from '../../src/tools/find-callable.ts';

interface Page {
  result: {
    items: Array<{ fqn: string }>;
    total: number;
    truncated: boolean;
  };
}

describe('Согласованная сортировка и пагинация', () => {
  let directory: string;
  let db: IndexDatabase;

  before(() => {
    directory = mkdtempSync(join(tmpdir(), 'bitrix-mcp-pagination-'));
    const path = join(directory, 'index.sqlite');
    const writable = new Database(path);
    writable.exec(`
      CREATE TABLE snapshot (
        id INTEGER PRIMARY KEY, sm_version TEXT, sm_version_date TEXT, indexed_at TEXT,
        indexer_version TEXT, schema_version INTEGER, file_count INTEGER, total_bytes INTEGER,
        parse_error_count INTEGER, short_open_tag_enabled INTEGER, docs_crawled_at TEXT,
        docs_source TEXT, docs_source_revision TEXT
      );
      INSERT INTO snapshot VALUES
        (1, '1.0.0', NULL, '2026-01-01T00:00:00Z', 'test', ${SUPPORTED_SCHEMA_VERSION}, 0, 0, 0, 1, NULL, NULL, NULL);
      CREATE TABLE index_root (id INTEGER PRIMARY KEY, path TEXT, label TEXT, is_core INTEGER);
      CREATE TABLE module (
        id INTEGER PRIMARY KEY, root_id INTEGER, code TEXT, version TEXT,
        version_date TEXT, version_source TEXT, rel_path TEXT
      );
      INSERT INTO module VALUES (1, NULL, 'test', '1.0.0', NULL, 'module_file', 'modules/test');
      CREATE TABLE file (id INTEGER PRIMARY KEY, root_id INTEGER, module_id INTEGER, rel_path TEXT);
      INSERT INTO file VALUES (1, NULL, 1, 'modules/test/pager.php');
      CREATE TABLE symbol (
        id INTEGER PRIMARY KEY, module_id INTEGER, file_id INTEGER, fqn TEXT, fqn_lower TEXT,
        short_name TEXT, short_name_lower TEXT, kind TEXT, parent_fqn TEXT, is_abstract INTEGER,
        is_final INTEGER, is_deprecated INTEGER, deprecated_note TEXT, api_style TEXT,
        doc_comment TEXT, line INTEGER
      );
      CREATE TABLE method (
        id INTEGER PRIMARY KEY, symbol_id INTEGER, name TEXT, name_lower TEXT, visibility TEXT,
        is_static INTEGER, is_abstract INTEGER, is_final INTEGER, return_type TEXT,
        signature TEXT, doc_comment TEXT, is_deprecated INTEGER, line INTEGER
      );
      CREATE TABLE global_function (
        id INTEGER PRIMARY KEY, module_id INTEGER, file_id INTEGER, fqn TEXT, fqn_lower TEXT,
        short_name TEXT, short_name_lower TEXT, signature TEXT, return_type TEXT,
        doc_comment TEXT, is_deprecated INTEGER, line INTEGER
      );
    `);

    const addClass = writable.prepare(`
      INSERT INTO symbol (id, module_id, file_id, fqn, fqn_lower, short_name, short_name_lower, kind,
                          is_abstract, is_final, is_deprecated, api_style, line)
      VALUES (?, 1, 1, ?, ?, ?, ?, 'class', 0, 0, 0, 'd7', 10)
    `);
    const addMethod = writable.prepare(`
      INSERT INTO method (id, symbol_id, name, name_lower, visibility, is_static, is_abstract,
                          is_final, signature, is_deprecated, line)
      VALUES (?, ?, 'run', 'run', 'public', 0, 0, 0, 'public function run(): void', 0, 20)
    `);

    for (const [id, name] of [[1, 'B'], [2, 'a']] as const) {
      const fqn = `\\Pager\\${name}`;
      addClass.run(id, fqn, fqn.toLowerCase(), name, name.toLowerCase());
      addMethod.run(id, id);
    }

    writable.prepare(`
      INSERT INTO global_function (id, module_id, file_id, fqn, fqn_lower, short_name,
                                   short_name_lower, signature, is_deprecated, line)
      VALUES (1, 1, 1, '\\Pager\\run', '\\pager\\run', 'run', 'run', 'function run(): void', 0, 30)
    `).run();
    writable.close();
    db = new IndexDatabase(path);
  });

  after(() => {
    db?.close();
    rmSync(directory, { recursive: true, force: true });
  });

  test('find_namespace возвращает все строки без повторов между страницами', () => {
    const tool = findNamespaceTool(db);
    const first = tool.handler({ namespace: 'Pager', limit: 1 }) as Page;
    const second = tool.handler({ namespace: 'Pager', limit: 1, offset: 1 }) as Page;

    assert.equal(first.result.total, 3);
    assert.equal(first.result.truncated, true);
    assert.deepEqual(
      [...first.result.items, ...second.result.items].map((item) => item.fqn),
      ['\\Pager\\B', '\\Pager\\a'],
    );
  });

  test('find_callable применяет единый порядок к методам и функциям', () => {
    const tool = findCallableTool(db);
    const pages = [0, 1, 2].map(
      (offset) => tool.handler({ name: 'run', kind: 'any', limit: 1, offset }) as Page,
    );

    assert.deepEqual(
      pages.flatMap((page) => page.result.items).map((item) => item.fqn),
      ['\\Pager\\B::run', '\\Pager\\a::run', '\\Pager\\run'],
    );
    assert.equal(pages[0]?.result.total, 3);
    assert.equal(pages[2]?.result.truncated, false);
  });
});
