import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';

import { indexAvailable, openIndex, registry } from '../helpers.ts';
import type { IndexDatabase } from '../../src/db/index.ts';

/**
 * Поиск по документации на кириллице (FR-032).
 *
 * Документация Битрикса русскоязычная, и токенизатор обязан с ней работать.
 * Проверяется также инвариант расхождения из FR-030: документация никогда
 * не перевешивает индекс кода.
 */
describe('Поиск по документации', { skip: !indexAvailable() }, () => {
  let db: IndexDatabase;
  let tool: ReturnType<typeof registry>[number];

  before(() => {
    db = openIndex();
    const found = registry(db).find((t) => t.name === 'bitrix_search_docs');
    assert.ok(found);
    tool = found;
  });

  after(() => db?.close());

  function search(args: Record<string, unknown>) {
    return tool.handler(args) as {
      result: {
        found?: boolean;
        results?: Array<{ title: string | null; section: string; url: string; excerpt: string }>;
        code_conflict?: { present_in_snapshot: boolean; doc_claims: boolean };
        note?: string;
      };
    };
  }

  test('русскоязычный запрос находит релевантные страницы', () => {
    const response = search({ query: 'кеширование' });

    assert.equal(response.result.found, true, 'Кириллический запрос ничего не нашёл');
    assert.ok((response.result.results ?? []).length > 0);

    const urls = (response.result.results ?? []).map((r) => r.url);
    assert.ok(
      urls.some((u) => u.includes('performance') || u.includes('cach')),
      `Выдача нерелевантна: ${urls.join(', ')}`,
    );
  });

  test('многословный запрос со служебными словами не ломает разбор', () => {
    // Дефисы, предлоги и короткие слова имеют значение в синтаксисе FTS5
    // и без экранирования превратили бы запрос в синтаксическую ошибку.
    const response = search({ query: 'как правильно работать с ORM-сущностью?' });

    assert.equal(response.result.found, true);
  });

  test('фильтр по разделу сужает выдачу', () => {
    const response = search({ query: 'безопасность', section: 'security' });

    if (response.result.found !== true) return;

    for (const row of response.result.results ?? []) {
      assert.equal(row.section, 'security');
    }
  });

  test('бессмысленный запрос даёт отказ, а не случайные страницы', () => {
    const response = search({ query: 'йцукенгшщзхъ фывапролджэ' });

    assert.equal(response.result.found, false);
  });

  test('запрос только из пунктуации возвращает явную причину', () => {
    const response = search({ query: '!!! -- ()' }) as {
      result: { found: false; reason: string };
    };

    assert.equal(response.result.found, false);
    assert.equal(response.result.reason, 'invalid_query');
  });

  test('расхождение документации и кода видимо и разрешается в пользу кода', () => {
    const response = search({ query: 'кеширование', check_symbol: 'CTotallyMadeUpClassXyz' });

    assert.ok(response.result.code_conflict, 'Расхождение не отражено в ответе');
    assert.equal(response.result.code_conflict.present_in_snapshot, false);
  });

  test('существующий символ расхождением не считается', () => {
    const response = search({ query: 'кеширование', check_symbol: 'IblockTable' });

    assert.equal(
      response.result.code_conflict,
      undefined,
      'Существующий в индексе символ помечен как расхождение',
    );
  });

  test('ответ напоминает, что документация не версионирована', () => {
    const response = search({ query: 'ORM' });

    assert.match(String(response.result.note), /не версионирован/);
  });
});
