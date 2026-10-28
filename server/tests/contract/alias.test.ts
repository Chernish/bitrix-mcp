import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';

import { indexAvailable, openIndex, registry } from '../helpers.ts';
import type { IndexDatabase } from '../../src/db/index.ts';

/**
 * Контрактный тест №4 — R-006.
 *
 * \Bitrix\Sale\OrderTable — именно та форма, которую напишет агент, но
 * объявления с таким именем в ядре нет: класс объявлен как
 * \Bitrix\Sale\Internals\OrderTable через class_alias. Ответ «не найдено»
 * здесь был бы отказом на существующий и повсеместно используемый класс.
 */
describe('Контракт №4: разрешение псевдонимов class_alias', { skip: !indexAvailable() }, () => {
  let db: IndexDatabase;

  before(() => {
    db = openIndex();
  });

  after(() => db?.close());

  test('поиск по псевдониму находит класс и отдаёт каноническое имя', () => {
    const find = registry(db).find((t) => t.name === 'bitrix_find_symbol');
    assert.ok(find);

    const response = find.handler({ name: '\\Bitrix\\Sale\\OrderTable' }) as {
      result: {
        found: boolean;
        symbols?: Array<{ fqn: string; canonical_fqn: string; module: string }>;
      };
    };

    assert.equal(response.result.found, true, 'Псевдоним не разрешён');

    const symbol = response.result.symbols?.[0];
    assert.ok(symbol);

    assert.equal(symbol.fqn, '\\Bitrix\\Sale\\OrderTable', 'Запрошенная форма имени потеряна');
    assert.equal(
      symbol.canonical_fqn,
      '\\Bitrix\\Sale\\Internals\\OrderTable',
      'Каноническое имя не возвращено',
    );
    assert.equal(symbol.module, 'sale');
  });

  test('методы доступны по имени псевдонима', () => {
    const check = registry(db).find((t) => t.name === 'bitrix_check_method');
    assert.ok(check);

    const response = check.handler({
      class: '\\Bitrix\\Sale\\OrderTable',
      method: 'getMap',
    }) as { result: { exists: boolean; class_exists: boolean } };

    assert.equal(response.result.class_exists, true);
  });

  test('в индексе есть псевдонимы', () => {
    const status = registry(db).find((t) => t.name === 'bitrix_index_status');
    assert.ok(status);

    const response = status.handler({}) as { result: { counts: { aliases: number } } };

    assert.ok(response.result.counts.aliases > 0, 'Псевдонимы не проиндексированы');
  });
});
