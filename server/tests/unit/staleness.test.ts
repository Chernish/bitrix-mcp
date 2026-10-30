import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync, copyFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import Database from 'better-sqlite3';

import { IndexDatabase } from '../../src/db/index.ts';
import {
  checkStaleness,
  checkDeepStaleness,
  readCurrentSmVersion,
  resetStalenessCache,
} from '../../src/staleness.ts';
import { INDEX_PATH, indexAvailable } from '../helpers.ts';
import { indexStatusTool } from '../../src/tools/index-status.ts';

/** Версии сборки и модулей проверяются совместно и возвращают согласованный stale. */
describe('Обнаружение рассинхрона', { skip: !indexAvailable() }, () => {
  const temps: string[] = [];
  const previousCoreRoot = process.env.BITRIX_CORE_ROOT;

  before(() => {
    delete process.env.BITRIX_CORE_ROOT;
  });

  after(() => {
    if (previousCoreRoot === undefined) {
      delete process.env.BITRIX_CORE_ROOT;
    } else {
      process.env.BITRIX_CORE_ROOT = previousCoreRoot;
    }
    temps.forEach((p) => rmSync(p, { recursive: true, force: true }));
  });

  function fakeCore(smVersion: string, modules: Record<string, string>): string {
    const dir = mkdtempSync(join(tmpdir(), 'bitrix-mcp-core-'));
    temps.push(dir);

    mkdirSync(join(dir, 'modules', 'main', 'classes', 'general'), { recursive: true });
    writeFileSync(
      join(dir, 'modules', 'main', 'classes', 'general', 'version.php'),
      `<?php\ndefine("SM_VERSION", "${smVersion}");\n`,
    );

    for (const [code, version] of Object.entries(modules)) {
      mkdirSync(join(dir, 'modules', code, 'install'), { recursive: true });
      writeFileSync(
        join(dir, 'modules', code, 'install', 'version.php'),
        `<?php\n$arModuleVersion = ["VERSION" => "${version}"];\n`,
      );
    }

    return dir;
  }

  function indexPointingAt(coreDir: string, mutate?: (db: Database.Database) => void): string {
    const copy = join(tmpdir(), `bitrix-mcp-idx-${temps.length}-${process.pid}.sqlite`);
    temps.push(copy);
    copyFileSync(INDEX_PATH, copy);

    const writable = new Database(copy);
    writable.prepare('UPDATE index_root SET path = ? WHERE is_core = 1').run(coreDir);

    if (existsSync(coreDir)) {
      writable.prepare('UPDATE module SET version = ? WHERE code = ?').run(
        readCurrentSmVersion(coreDir),
        'main',
      );
      const modules = writable
        .prepare("SELECT version, rel_path FROM module WHERE code <> 'main'")
        .all() as Array<{ version: string | null; rel_path: string }>;

      for (const row of modules) {
        if (row.version === null) continue;
        const directory = join(coreDir, row.rel_path, 'install');
        const path = join(directory, 'version.php');
        if (existsSync(path)) continue;
        mkdirSync(directory, { recursive: true });
        writeFileSync(path, `<?php\n$arModuleVersion = ["VERSION" => "${row.version}"];\n`);
      }
    }

    mutate?.(writable);
    writable.close();

    return copy;
  }

  test('версия сборки читается из classes/general, а не из install', () => {
    const core = fakeCore('25.100.300', {});

    assert.equal(readCurrentSmVersion(core), '25.100.300');
  });

  test('BITRIX_CORE_ROOT заменяет путь из индекса для runtime-проверок', () => {
    const core = fakeCore('26.800.0', {});
    const idx = indexPointingAt('/recorded/core');
    const previous = process.env.BITRIX_CORE_ROOT;
    process.env.BITRIX_CORE_ROOT = core;
    const db = new IndexDatabase(idx);

    try {
      assert.equal(db.coreRoot(), core);
    } finally {
      db.close();
      if (previous === undefined) {
        delete process.env.BITRIX_CORE_ROOT;
      } else {
        process.env.BITRIX_CORE_ROOT = previous;
      }
    }
  });

  test('совпадение версии сборки означает отсутствие рассинхрона', () => {
    const core = fakeCore('25.100.300', {});
    const idx = indexPointingAt(core, (w) =>
      w.prepare("UPDATE snapshot SET sm_version = '25.100.300'").run(),
    );

    resetStalenessCache();
    const db = new IndexDatabase(idx);

    try {
      const result = checkStaleness(db);
      assert.equal(result.stale, false);
      assert.equal(result.reason, null);
    } finally {
      db.close();
      resetStalenessCache();
    }
  });

  test('смена версии сборки обнаруживается первым уровнем', () => {
    const core = fakeCore('26.0.0', {});
    const idx = indexPointingAt(core, (w) =>
      w.prepare("UPDATE snapshot SET sm_version = '25.100.300'").run(),
    );

    resetStalenessCache();
    const db = new IndexDatabase(idx);

    try {
      const result = checkStaleness(db);
      assert.equal(result.stale, true);
      assert.match(String(result.reason), /25\.100\.300 -> 26\.0\.0/);
    } finally {
      db.close();
      resetStalenessCache();
    }
  });

  test('недоступный корень ядра считается рассинхроном', () => {
    const idx = indexPointingAt('/nonexistent/path/to/bitrix');

    resetStalenessCache();
    const db = new IndexDatabase(idx);

    try {
      const result = checkStaleness(db);
      assert.equal(result.stale, true);
      assert.match(String(result.reason), /недоступен/);
    } finally {
      db.close();
      resetStalenessCache();
    }
  });

  test('обычный и deep-ответы ловят обновление модуля при неизменной версии сборки', () => {
    const core = fakeCore('25.100.300', { crm: '99.0.0' });
    const idx = indexPointingAt(core, (w) => {
      w.prepare("UPDATE snapshot SET sm_version = '25.100.300'").run();
      w.prepare("UPDATE module SET version = '24.1300.0', rel_path = 'modules/crm' WHERE code = 'crm'").run();
    });

    resetStalenessCache();
    const db = new IndexDatabase(idx);

    try {
      const regular = checkStaleness(db);
      assert.equal(regular.stale, true, 'Обычный ответ обязан обнаружить расхождение модуля');
      assert.match(String(regular.reason), /версии модулей разошлись:.*crm/);

      const deep = checkDeepStaleness(db);

      assert.equal(deep.stale, regular.stale, 'Обычная и deep-проверки противоречат друг другу');
      assert.equal(deep.reason, regular.reason);
      assert.ok(deep.checked > 0);

      const crm = deep.drifted.find((d) => d.module === 'crm');
      assert.ok(crm, 'Разошедшийся модуль не попал в отчёт');
      assert.equal(crm.indexed, '24.1300.0');
      assert.equal(crm.current, '99.0.0');

      const status = indexStatusTool(db).handler({ deep: true }) as {
        provenance: { stale: boolean; stale_reason: string | null };
        result: { deep_check: { drifted: unknown[] } };
      };
      assert.equal(status.provenance.stale, true);
      assert.equal(status.provenance.stale_reason, regular.reason);
      assert.equal(status.result.deep_check.drifted.length, deep.drifted.length);
    } finally {
      db.close();
      resetStalenessCache();
    }
  });
});
