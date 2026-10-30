import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import Database from 'better-sqlite3';

import { IndexDatabase, SUPPORTED_SCHEMA_VERSION } from '../../src/db/index.ts';
import { checkMethodTool } from '../../src/tools/check-method.ts';
import type { Envelope } from '../../src/types.ts';

interface CheckResult {
  exists: boolean;
  class_exists: boolean;
  declared_in?: string | null;
  file?: string;
  did_you_mean?: { guess: boolean; names: string[] };
}

describe('Точная проверка методов', () => {
  let directory: string;
  let db: IndexDatabase;

  before(() => {
    directory = mkdtempSync(join(tmpdir(), 'bitrix-mcp-methods-'));
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
      CREATE TABLE symbol (
        id INTEGER PRIMARY KEY, module_id INTEGER, file_id INTEGER, fqn TEXT, fqn_lower TEXT,
        short_name TEXT, short_name_lower TEXT, kind TEXT, parent_fqn TEXT, is_abstract INTEGER,
        is_final INTEGER, is_deprecated INTEGER, deprecated_note TEXT, api_style TEXT,
        doc_comment TEXT, line INTEGER
      );
      CREATE TABLE symbol_interface (symbol_id INTEGER, interface_fqn TEXT);
      CREATE TABLE symbol_alias (
        id INTEGER PRIMARY KEY, module_id INTEGER, file_id INTEGER, alias_fqn TEXT,
        alias_fqn_lower TEXT, alias_short_lower TEXT, target_fqn TEXT, line INTEGER
      );
      CREATE TABLE symbol_trait (symbol_id INTEGER, trait_fqn TEXT, trait_fqn_lower TEXT);
      CREATE TABLE method (
        id INTEGER PRIMARY KEY, symbol_id INTEGER, name TEXT, name_lower TEXT, visibility TEXT,
        is_static INTEGER, is_abstract INTEGER, is_final INTEGER, return_type TEXT,
        signature TEXT, doc_comment TEXT, is_deprecated INTEGER, line INTEGER
      );
    `);

    let symbolId = 0;
    let fileId = 0;
    let methodId = 0;
    const addSymbol = (
      fqn: string,
      kind: 'class' | 'trait',
      methods: string[] = [],
      parent: string | null = null,
      traits: string[] = [],
    ): number => {
      symbolId += 1;
      fileId += 1;
      const shortName = fqn.split('\\').at(-1) ?? fqn;
      writable.prepare('INSERT INTO file (id, module_id, rel_path) VALUES (?, 1, ?)').run(
        fileId,
        `modules/test/${shortName}.php`,
      );
      writable.prepare(`
        INSERT INTO symbol (
          id, module_id, file_id, fqn, fqn_lower, short_name, short_name_lower, kind,
          parent_fqn, is_abstract, is_final, is_deprecated, api_style, line
        ) VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 'd7', 10)
      `).run(symbolId, fileId, fqn, fqn.toLowerCase(), shortName, shortName.toLowerCase(), kind, parent);

      for (const trait of traits) {
        writable.prepare('INSERT INTO symbol_trait VALUES (?, ?, ?)').run(
          symbolId,
          trait,
          trait.toLowerCase(),
        );
      }

      for (const name of methods) {
        methodId += 1;
        writable.prepare(`
          INSERT INTO method (
            id, symbol_id, name, name_lower, visibility, is_static, is_abstract,
            is_final, signature, is_deprecated, line
          ) VALUES (?, ?, ?, ?, 'public', 0, 0, 0, ?, 0, 20)
        `).run(methodId, symbolId, name, name.toLowerCase(), `public function ${name}(): void`);
      }

      return symbolId;
    };

    addSymbol('\\Fixture\\ProductTable', 'class', ['save']);
    addSymbol('\\Fixture\\Base', 'class', ['save', 'parentOnly']);
    addSymbol('\\Fixture\\Child', 'class', ['save'], '\\Fixture\\Base');
    addSymbol('\\Fixture\\Grandchild', 'class', [], '\\Fixture\\Child');
    addSymbol('\\Fixture\\NestedTrait', 'trait', ['fromTrait']);
    addSymbol('\\Fixture\\OuterTrait', 'trait', [], null, ['\\Fixture\\NestedTrait']);
    addSymbol('\\Fixture\\TraitConsumer', 'class', [], '\\Fixture\\Base', ['\\Fixture\\OuterTrait']);
    addSymbol('\\Fixture\\CycleA', 'class', [], '\\Fixture\\CycleB');
    addSymbol('\\Fixture\\CycleB', 'class', [], '\\Fixture\\CycleA');
    writable.close();
    db = new IndexDatabase(path);
  });

  after(() => {
    db?.close();
    rmSync(directory, { recursive: true, force: true });
  });

  const call = (className: string, method: string): Envelope<CheckResult> =>
    checkMethodTool(db).handler({ class: className, method }) as Envelope<CheckResult>;

  test('похожий класс не подменяет запрошенный', () => {
    const response = call('Product', 'save');
    assert.equal(response.result.class_exists, false);
    assert.equal(response.result.exists, false);
    assert.equal(response.result.declared_in, undefined);
    assert.ok(response.result.did_you_mean?.names.includes('ProductTable'));
  });

  test('метод родителя доступен потомку с фактическим местом объявления', () => {
    const response = call('Grandchild', 'parentOnly');
    assert.equal(response.result.exists, true);
    assert.equal(response.result.declared_in, '\\Fixture\\Base');
    assert.equal(response.result.file, 'modules/test/Base.php');
  });

  test('метод вложенного трейта имеет приоритет над методом родителя', () => {
    const response = call('TraitConsumer', 'fromTrait');
    assert.equal(response.result.exists, true);
    assert.equal(response.result.declared_in, '\\Fixture\\NestedTrait');
    assert.equal(response.result.file, 'modules/test/NestedTrait.php');
  });

  test('собственный override имеет приоритет над родителем', () => {
    const response = call('Child', 'save');
    assert.equal(response.result.exists, true);
    assert.equal(response.result.declared_in, '\\Fixture\\Child');
  });

  test('циклические родители завершаются отказом', () => {
    const response = call('CycleA', 'missing');
    assert.equal(response.result.class_exists, true);
    assert.equal(response.result.exists, false);
  });
});
