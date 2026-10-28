import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';

import { HAPPY_ARGS, indexAvailable, openIndex, registry, walkStrings } from '../helpers.ts';
import type { IndexDatabase } from '../../src/db/index.ts';

/**
 * Контрактный тест №2 — Принцип IV и SC-007.
 *
 * Проверяет два разных свойства: что ответ не содержит выгрузки файла целиком
 * и что типичный ответ укладывается в 2000 символов. Второе не следует
 * из первого: можно не отдавать файлы и всё равно раздуть выдачу списком
 * из сотни совпадений.
 */
describe('Контракт №2: экономия контекста', { skip: !indexAvailable() }, () => {
  let db: IndexDatabase;

  before(() => {
    db = openIndex();
  });

  after(() => db?.close());

  test('ни один инструмент не возвращает содержимое файла целиком', () => {
    for (const tool of registry(db)) {
      const response = tool.handler(HAPPY_ARGS[tool.name] ?? {});

      walkStrings(response, (s) => {
        assert.equal(
          s.includes('<?php'),
          false,
          `${tool.name}: в ответе встречается исходный код PHP — ` +
            `инструмент обязан отдавать сигнатуру, путь и строку, а не файл`,
        );
      });
    }
  });

  test('типичный ответ укладывается в 2000 символов (SC-007)', () => {
    const LIMIT = 2000;

    for (const tool of registry(db)) {
      const response = tool.handler(HAPPY_ARGS[tool.name] ?? {});
      const size = JSON.stringify(response).length;

      assert.ok(
        size <= LIMIT,
        `${tool.name}: ответ ${size} символов при пределе ${LIMIT}. ` +
          `Раздутая выдача вытесняет из контекста агента саму задачу`,
      );
    }
  });

  test('широкий запрос не выгружает всю базу', () => {
    const find = registry(db).find((t) => t.name === 'bitrix_find_symbol');
    assert.ok(find);

    const response = find.handler({ name: 'a' }) as { result: { symbols?: unknown[] } };
    const symbols = response.result.symbols ?? [];

    assert.ok(
      symbols.length <= 50,
      `Широкий запрос вернул ${symbols.length} символов без ограничения`,
    );
  });

  test('максимальные лимиты обоих поисков остаются в бюджете', () => {
    const cases = [
      { name: 'bitrix_search_code', args: { query: 'get user event', limit: 10 } },
      { name: 'bitrix_search_docs', args: { query: 'HTTP клиент', limit: 5 } },
    ];

    for (const item of cases) {
      const tool = registry(db).find((candidate) => candidate.name === item.name);
      assert.ok(tool);

      const response = tool.handler(item.args);
      assert.ok(
        JSON.stringify(response).length <= 2_000,
        `${item.name}: максимальный limit вышел за бюджет`,
      );
    }
  });
});
