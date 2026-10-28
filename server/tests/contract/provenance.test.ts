import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';

import { HAPPY_ARGS, indexAvailable, openIndex, registry } from '../helpers.ts';
import type { IndexDatabase } from '../../src/db/index.ts';
import type { Envelope } from '../../src/types.ts';

/**
 * Контрактный тест №1 — NON-NEGOTIABLE Принцип II.
 *
 * Параметризован по реестру инструментов, а не по написанному вручную списку.
 * Это существенно: инструменты добавляются в фазах 4–9, и тест со статическим
 * списком остался бы зелёным, перестав покрывать новые. Такой тест хуже
 * отсутствующего — он создаёт видимость защиты там, где её нет.
 */
describe('Контракт №1: provenance в каждом ответе', { skip: !indexAvailable() }, () => {
  let db: IndexDatabase;

  before(() => {
    db = openIndex();
  });

  after(() => db?.close());

  test('каждый инструмент реестра описан в HAPPY_ARGS', () => {
    for (const tool of registry(db)) {
      assert.ok(
        HAPPY_ARGS[tool.name],
        `Инструмент ${tool.name} добавлен в реестр, но не описан в HAPPY_ARGS — ` +
          `контрактные инварианты его не покрывают`,
      );
    }
  });

  test('каждый инструмент возвращает provenance с версией сборки', () => {
    for (const tool of registry(db)) {
      const response = tool.handler(HAPPY_ARGS[tool.name] ?? {}) as Envelope<unknown>;

      assert.ok(response.provenance, `${tool.name}: нет конверта provenance`);
      assert.ok(
        'sm_version' in response.provenance,
        `${tool.name}: в provenance нет sm_version`,
      );
      assert.ok(
        response.provenance.snapshot_id,
        `${tool.name}: в provenance нет snapshot_id`,
      );
      assert.ok(
        ['active', 'archive', 'direct'].includes(response.provenance.snapshot_role),
        `${tool.name}: неизвестная роль снимка`,
      );
      assert.equal(
        typeof response.provenance.stale,
        'boolean',
        `${tool.name}: признак stale обязан присутствовать в каждом ответе`,
      );
    }
  });

  test('атрибуция к модулю либо несёт версию, либо помечена как неизвестная', () => {
    for (const tool of registry(db)) {
      const response = tool.handler(HAPPY_ARGS[tool.name] ?? {}) as Envelope<unknown>;
      const p = response.provenance;

      if (p.module === undefined) continue;

      const hasVersion = p.module_version != null;
      const markedUnknown = p.version_unknown === true;

      assert.ok(
        hasVersion || markedUnknown,
        `${tool.name}: модуль ${p.module} указан без версии и без признака version_unknown. ` +
          `Факт без версионной атрибуции — дефект, а не косметика (Принцип II)`,
      );

      assert.equal(
        hasVersion && markedUnknown,
        false,
        `${tool.name}: version_unknown выставлен при известной версии`,
      );
    }
  });

  test('версия сборки не подменяет неизвестную версию модуля', () => {
    const tool = registry(db).find((t) => t.name === 'bitrix_find_symbol');
    assert.ok(tool);

    const response = tool.handler({ name: 'IblockTable' }) as Envelope<unknown>;
    const p = response.provenance;

    if (p.version_unknown === true) {
      assert.notEqual(
        p.module_version,
        p.sm_version,
        'Неизвестная версия модуля подменена версией сборки — прямой запрет Принципа II',
      );
    }
  });
});
