import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import Database from 'better-sqlite3';

import { IndexDatabase, SUPPORTED_SCHEMA_VERSION } from '../../src/db/index.ts';
import { getComponentTool } from '../../src/tools/get-component.ts';
import { listComponentsTool } from '../../src/tools/list-components.ts';

describe('Объединение описания и параметров компонента', () => {
  let directory: string;
  let db: IndexDatabase;
  let writable: Database.Database;

  before(() => {
    directory = mkdtempSync(join(tmpdir(), 'bitrix-mcp-components-'));
    const path = join(directory, 'index.sqlite');
    writable = new Database(path);
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
      INSERT INTO index_root VALUES (1, NULL, 'core', 1);
      CREATE TABLE module (
        id INTEGER PRIMARY KEY, root_id INTEGER, code TEXT, version TEXT,
        version_date TEXT, version_source TEXT, rel_path TEXT
      );
      INSERT INTO module VALUES (1, 1, 'test', '1.0.0', NULL, 'module_file', 'modules/test');
      CREATE TABLE file (id INTEGER PRIMARY KEY, root_id INTEGER, module_id INTEGER, rel_path TEXT);
      CREATE TABLE component (
        id INTEGER PRIMARY KEY, module_id INTEGER, file_id INTEGER, namespace TEXT, name TEXT,
        full_name TEXT, title TEXT, description TEXT, params_resolved INTEGER
      );
      CREATE TABLE component_param (
        id INTEGER PRIMARY KEY, component_id INTEGER, position INTEGER, code TEXT, type TEXT,
        default_repr TEXT, is_default_resolved INTEGER, parent_group TEXT, multiple INTEGER, values_repr TEXT
      );
      INSERT INTO file VALUES
        (1, 1, 1, 'modules/test/install/components/bitrix/demo.list/.description.php'),
        (2, 1, 1, 'modules/test/install/components/bitrix/demo.list/.parameters.php');
      INSERT INTO component VALUES
        (1, 1, 1, 'bitrix', 'demo.list', 'bitrix:demo.list', 'Demo', 'Description', 1),
        (2, 1, 2, 'bitrix', 'demo.list', 'bitrix:demo.list', NULL, NULL, 1);
      INSERT INTO component_param VALUES
        (1, 2, 0, 'ID', 'STRING', '1', 1, 'BASE', 0, NULL),
        (2, 2, 1, 'FILTER', 'STRING', NULL, 0, 'BASE', 0, NULL);
    `);
    db = new IndexDatabase(path);
  });

  after(() => {
    db?.close();
    writable?.close();
    rmSync(directory, { recursive: true, force: true });
  });

  test('get_component возвращает описание и все параметры одной записью', () => {
    const response = getComponentTool(db).handler({ name: 'bitrix:demo.list' }) as {
      result: {
        found: boolean;
        title: string;
        description: string;
        total: number;
        unresolved_count: number;
        files: string[];
        params: Array<{ code: string }>;
      };
    };

    assert.equal(response.result.found, true);
    assert.equal(response.result.title, 'Demo');
    assert.equal(response.result.description, 'Description');
    assert.equal(response.result.total, 2);
    assert.equal(response.result.unresolved_count, 1);
    assert.deepEqual(response.result.params.map((param) => param.code), ['ID', 'FILTER']);
    assert.equal(response.result.files.length, 2);
  });

  test('list_components не дублирует component из двух файлов', () => {
    const response = listComponentsTool(db).handler({ name_filter: 'demo.list' }) as {
      result: {
        total: number;
        components: Array<{ title: string; params: number; params_resolved: boolean }>;
      };
    };

    assert.equal(response.result.total, 1);
    assert.deepEqual(response.result.components, [{
      name: 'bitrix:demo.list',
      title: 'Demo',
      module: 'test',
      module_version: '1.0.0',
      version_unknown: false,
      params: 2,
      params_resolved: true,
      file: 'modules/test/install/components/bitrix/demo.list/.parameters.php',
    }]);
  });

  test('одноимённые компоненты разных корней не смешиваются', () => {
    writable.exec(`
      INSERT INTO index_root VALUES (2, NULL, 'extra', 0);
      INSERT INTO module VALUES (2, 2, 'test', '2.0.0', NULL, 'module_file', 'modules/test');
      INSERT INTO file VALUES (3, 2, 2, 'modules/test/install/components/bitrix/demo.list/.description.php');
      INSERT INTO component VALUES
        (3, 2, 3, 'bitrix', 'demo.list', 'bitrix:demo.list', 'Other version', NULL, 1);
    `);

    const response = getComponentTool(db).handler({ name: 'bitrix:demo.list' }) as {
      result: { found: boolean; reason: string };
    };
    assert.equal(response.result.found, false);
    assert.equal(response.result.reason, 'ambiguous');
  });
});
