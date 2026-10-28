import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';

import { indexAvailable, openIndex, registry } from '../helpers.ts';
import type { IndexDatabase } from '../../src/db/index.ts';

/**
 * Контрактный тест №7 — Принцип IV.
 *
 * Молчаливая обрезка выдачи читается агентом как «это всё» и приводит
 * к неверным выводам об отсутствии чего-либо. Усечение обязано быть явным
 * и сопровождаться полным числом совпадений.
 */
describe('Контракт №7: явное усечение выдачи', { skip: !indexAvailable() }, () => {
  let db: IndexDatabase;

  before(() => {
    db = openIndex();
  });

  after(() => db?.close());

  test('усечённый поиск помечает truncated и сообщает total', () => {
    const find = registry(db).find((t) => t.name === 'bitrix_find_symbol');
    assert.ok(find);

    const response = find.handler({ name: 'Table', limit: 2 }) as {
      result: { found: boolean; symbols: unknown[]; total: number; truncated: boolean };
    };

    if (!response.result.found) return;

    assert.equal(typeof response.result.total, 'number', 'Нет общего числа совпадений');
    assert.equal(typeof response.result.truncated, 'boolean', 'Нет признака усечения');

    if (response.result.total > response.result.symbols.length) {
      assert.equal(
        response.result.truncated,
        true,
        'Выдача усечена, но признак не выставлен — агент решит, что это всё',
      );
    }
  });

  test('методы класса страничные и помечают усечение', () => {
    const getClass = registry(db).find((t) => t.name === 'bitrix_get_class');
    assert.ok(getClass);

    const first = getClass.handler({ fqn: 'IblockTable', limit: 1 }) as {
      result: { found: boolean; methods: unknown[]; total: number; truncated: boolean };
    };

    assert.equal(first.result.found, true);
    assert.ok(first.result.methods.length <= 1);

    if (first.result.total > 1) {
      assert.equal(first.result.truncated, true);

      const second = getClass.handler({ fqn: 'IblockTable', limit: 1, offset: 1 }) as {
        result: { methods: Array<{ name: string }> };
      };

      assert.notDeepEqual(
        second.result.methods,
        first.result.methods,
        'Смещение не влияет на выдачу — постраничная догрузка не работает',
      );
    }
  });

  test('лимит по умолчанию не отдаёт весь класс целиком', () => {
    const getClass = registry(db).find((t) => t.name === 'bitrix_get_class');
    assert.ok(getClass);

    const response = getClass.handler({ fqn: 'CIBlockElement', visibility: 'any' }) as {
      result: { found: boolean; methods?: unknown[] };
    };

    if (response.result.found) {
      assert.ok(
        (response.result.methods ?? []).length <= 40,
        'Класс старого API отдан без ограничения — выдача вытеснит задачу из контекста',
      );
    }
  });
});
