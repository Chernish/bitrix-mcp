import { test, describe, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, rmSync, copyFileSync } from 'node:fs';
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

/**
 * Уровни обнаружения рассинхрона.
 *
 * Первый уровень (версия сборки) выполняется на каждый ответ и потому обязан
 * быть дешёвым. Второй (карта версий модулей) ловит обновление отдельного
 * модуля, при котором версия сборки не меняется, — случай, который первый
 * уровень пропускает по построению.
 */
describe('Обнаружение рассинхрона', { skip: !indexAvailable() }, () => {
  const temps: string[] = [];

  after(() => temps.forEach((p) => rmSync(p, { recursive: true, force: true })));

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
    mutate?.(writable);
    writable.close();

    return copy;
  }

  test('версия сборки читается из classes/general, а не из install', () => {
    const core = fakeCore('25.100.300', {});

    assert.equal(readCurrentSmVersion(core), '25.100.300');
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

  test('второй уровень ловит обновление модуля при неизменной версии сборки', () => {
    // Именно тот случай, который первый уровень пропускает по построению.
    const core = fakeCore('25.100.300', { crm: '99.0.0' });
    const idx = indexPointingAt(core, (w) => {
      w.prepare("UPDATE snapshot SET sm_version = '25.100.300'").run();
      w.prepare("UPDATE module SET version = '24.1300.0', rel_path = 'modules/crm' WHERE code = 'crm'").run();
    });

    resetStalenessCache();
    const db = new IndexDatabase(idx);

    try {
      assert.equal(
        checkStaleness(db).stale,
        false,
        'Первый уровень не должен видеть расхождение: версия сборки не менялась',
      );

      resetStalenessCache();
      const deep = checkDeepStaleness(db);

      assert.equal(deep.stale, true, 'Второй уровень обязан обнаружить расхождение версий модулей');
      assert.ok(deep.checked > 0);

      const crm = deep.drifted.find((d) => d.module === 'crm');
      assert.ok(crm, 'Разошедшийся модуль не попал в отчёт');
      assert.equal(crm.indexed, '24.1300.0');
      assert.equal(crm.current, '99.0.0');
    } finally {
      db.close();
      resetStalenessCache();
    }
  });
});
