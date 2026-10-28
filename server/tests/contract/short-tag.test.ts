import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';

import { indexAvailable, openIndex, registry } from '../helpers.ts';
import type { IndexDatabase } from '../../src/db/index.ts';

/**
 * Контрактный тест №5 — регрессия R-001, проверяемая через MCP-инструмент.
 *
 * 27 652 файла ядра (41%) открываются коротким тегом `<?`. При выключенном
 * short_open_tag парсер молча возвращает такой файл как HTML, и классы из него
 * исчезают из индекса без единой ошибки.
 *
 * Проверка на уровне инструмента, а не парсера, нужна отдельно: потеря может
 * произойти и позже — при обходе, при атрибуции, при записи. Тест ловит
 * результат, а не механизм.
 */
describe('Контракт №5: классы из файлов с коротким тегом', { skip: !indexAvailable() }, () => {
  let db: IndexDatabase;

  before(() => {
    db = openIndex();
  });

  after(() => db?.close());

  test('индекс построен при включённом short_open_tag', () => {
    const status = registry(db).find((t) => t.name === 'bitrix_index_status');
    assert.ok(status);

    const response = status.handler({}) as {
      result: { short_open_tag_enabled: boolean; counts: { short_open_tag_files: number } };
    };

    assert.equal(
      response.result.short_open_tag_enabled,
      true,
      'Индекс построен при выключенной настройке и потому неполон',
    );

    assert.ok(
      response.result.counts.short_open_tag_files > 1000,
      `Файлов с коротким тегом найдено ${response.result.counts.short_open_tag_files}. ` +
        `Ожидались тысячи: их отсутствие означает, что обход их не увидел`,
    );
  });

  test('символы из файлов с коротким тегом присутствуют в индексе', () => {
    const symbolsFromShortTagFiles = db.count(
      `SELECT COUNT(*) AS c FROM symbol s
       JOIN file f ON f.id = s.file_id
       WHERE f.had_short_open_tag = 1`,
    );

    assert.ok(
      symbolsFromShortTagFiles > 0,
      'Из файлов с коротким тегом не извлечено ни одного символа — ' +
        'ровно тот тихий отказ, который описан в R-001',
    );
  });

  test('такой символ находится через инструмент', () => {
    const row = db.get<{ short_name: string }>(
      `SELECT s.short_name FROM symbol s
       JOIN file f ON f.id = s.file_id
       WHERE f.had_short_open_tag = 1 AND s.kind = 'class'
       ORDER BY s.id LIMIT 1`,
    );

    assert.ok(row, 'В индексе нет ни одного класса из файла с коротким тегом');

    const find = registry(db).find((t) => t.name === 'bitrix_find_symbol');
    assert.ok(find);

    const response = find.handler({ name: row.short_name }) as { result: { found: boolean } };

    assert.equal(
      response.result.found,
      true,
      `Класс ${row.short_name} есть в индексе, но инструмент его не находит`,
    );
  });
});
