import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';

import { MISSING_ARGS, indexAvailable, openIndex, registry } from '../helpers.ts';
import type { IndexDatabase } from '../../src/db/index.ts';

/**
 * Контрактный тест №3 — Принцип I и SC-003.
 *
 * Правдоподобная выдумка вреднее отказа: агент, получивший «не найдено»,
 * сходит и проверит; агент, получивший уверенный несуществующий метод,
 * напишет код, который сломается позже и дороже.
 */
describe('Контракт №3: отказ вместо выдумки', { skip: !indexAvailable() }, () => {
  let db: IndexDatabase;

  before(() => {
    db = openIndex();
  });

  after(() => db?.close());

  test('несуществующее имя даёт явный отказ', () => {
    for (const tool of registry(db)) {
      const args = MISSING_ARGS[tool.name];

      if (!args) continue;

      const response = tool.handler(args) as {
        result: { found?: boolean; exists?: boolean; class_exists?: boolean };
      };

      const denied =
        response.result.found === false ||
        (response.result.exists === false && response.result.class_exists === false);

      assert.ok(denied, `${tool.name}: несуществующее имя не дало отказа`);
    }
  });

  test('похожие имена не подмешиваются в основной результат', () => {
    const find = registry(db).find((t) => t.name === 'bitrix_find_symbol');
    assert.ok(find);

    const response = find.handler({ name: 'CTotallyMadeUpClassXyz' }) as {
      result: {
        found: boolean;
        symbols?: unknown[];
        did_you_mean?: { guess: boolean };
      };
    };

    assert.equal(response.result.found, false);
    assert.equal(
      response.result.symbols,
      undefined,
      'Отказ не должен содержать списка символов: похожее — не результат',
    );

    if (response.result.did_you_mean) {
      assert.equal(
        response.result.did_you_mean.guess,
        true,
        'Подсказка обязана быть явно помечена как догадка, а не как факт',
      );
    }
  });

  test('различает несуществующий класс и несуществующий метод', () => {
    const check = registry(db).find((t) => t.name === 'bitrix_check_method');
    assert.ok(check);

    const badClass = check.handler({ class: 'CNoSuchClassXyz', method: 'anything' }) as {
      result: { exists: boolean; class_exists: boolean };
    };
    assert.equal(badClass.result.class_exists, false);

    const badMethod = check.handler({ class: 'IblockTable', method: 'getAllQuicklyXyz' }) as {
      result: { exists: boolean; class_exists: boolean };
    };
    assert.equal(badMethod.result.class_exists, true, 'Класс существует');
    assert.equal(badMethod.result.exists, false, 'Метода нет');
  });
});
