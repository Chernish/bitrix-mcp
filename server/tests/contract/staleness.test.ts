import { test, describe, after } from 'node:test';
import assert from 'node:assert/strict';

import { HAPPY_ARGS, INDEX_PATH, cleanup, indexAvailable, makeStaleIndexCopy, registry } from '../helpers.ts';
import { IndexDatabase } from '../../src/db/index.ts';
import { resetStalenessCache } from '../../src/staleness.ts';
import type { Envelope } from '../../src/types.ts';

/**
 * Контрактный тест №6 — FR-031 и Принцип II.
 *
 * Проверяет, что признак устаревания попадает в ответ КАЖДОГО инструмента,
 * а не только в диагностический. Флаг только в диагностике был бы бесполезен:
 * агент, не спросивший о состоянии индекса, получил бы устаревший факт
 * без предупреждения.
 */
describe('Контракт №6: обнаружение рассинхрона', { skip: !indexAvailable() }, () => {
  const copies: string[] = [];

  after(() => copies.forEach(cleanup));

  test('при подменённом sm_version все инструменты выставляют stale', () => {
    const copy = makeStaleIndexCopy();
    copies.push(copy);

    resetStalenessCache();
    const db = new IndexDatabase(copy);

    try {
      const tools = registry(db);
      assert.ok(tools.length > 0);

      for (const tool of tools) {
        const response = tool.handler(HAPPY_ARGS[tool.name] ?? {}) as Envelope<unknown>;

        assert.equal(
          response.provenance.stale,
          true,
          `${tool.name}: рассинхрон не отражён в ответе. ` +
            `Агент, не спросивший о состоянии индекса, получит устаревший факт молча`,
        );

        assert.ok(
          response.provenance.stale_reason,
          `${tool.name}: stale выставлен без объяснения причины`,
        );

        assert.match(
          response.provenance.stale_reason,
          /sm_version/,
          `${tool.name}: причина не называет расхождение версий`,
        );
      }
    } finally {
      db.close();
      resetStalenessCache();
    }
  });

  test('на актуальном индексе stale не выставляется', () => {
    resetStalenessCache();
    const db = new IndexDatabase(INDEX_PATH);

    try {
      const tool = registry(db).find((t) => t.name === 'bitrix_index_status');
      assert.ok(tool);

      const response = tool.handler({}) as Envelope<unknown>;

      assert.equal(response.provenance.stale, false, 'Ложное срабатывание детекта рассинхрона');
      assert.equal(response.provenance.stale_reason, null);
    } finally {
      db.close();
      resetStalenessCache();
    }
  });
});
