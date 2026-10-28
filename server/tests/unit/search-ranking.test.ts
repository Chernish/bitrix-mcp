import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import Database from 'better-sqlite3';

import { indexAvailable, openIndex } from '../helpers.ts';
import {
  MAX_QUERY_TOKENS,
  normalizeSearchLimit,
  rankedSearch,
  tokenizeQuery,
} from '../../src/db/search.ts';
import { IndexDatabase, SUPPORTED_SCHEMA_VERSION } from '../../src/db/index.ts';

/**
 * Ранжирование поиска (спека 002-search-ranking).
 *
 * Ключевой инвариант FR-001/FR-002: документ со всеми словами запроса стоит
 * выше документа с частичным совпадением; при равенстве порядок
 * детерминирован (FR-009).
 */
describe('Разбор запроса', () => {
  test('дубликаты слова не увеличивают его вклад', () => {
    assert.deepEqual(tokenizeQuery('cache cache CACHE'), ['cache']);
  });

  test('служебные символы FTS удаляются', () => {
    assert.deepEqual(tokenizeQuery('"как" работать:с*orm()'), ['как', 'работать', 'orm']);
  });

  test('токены длиной от 2 символов сохраняются (FR-004)', () => {
    assert.ok(tokenizeQuery('d7 IO ORM').length === 3);
  });

  test('запрос без допустимых токенов даёт пустой список (FR-008)', () => {
    assert.deepEqual(tokenizeQuery('!!! ;;: ()'), []);
    assert.deepEqual(tokenizeQuery('   '), []);
  });

  test('сверхдлинный запрос ограничен по числу токенов', () => {
    const many = Array.from({ length: 50 }, (_, i) => `word${i}`).join(' ');
    assert.equal(tokenizeQuery(many).length, MAX_QUERY_TOKENS);
  });

  test('лимит результата целый, положительный и ограниченный', () => {
    assert.equal(normalizeSearchLimit(-100, 6, 10), 1);
    assert.equal(normalizeSearchLimit(3.9, 6, 10), 3);
    assert.equal(normalizeSearchLimit(1_000, 6, 10), 10);
    assert.equal(normalizeSearchLimit('не число', 6, 10), 6);
  });
});

describe('Ранжирование на независимом индексе', () => {
  let path: string;
  let db: IndexDatabase;

  before(() => {
    path = join(tmpdir(), `bitrix-search-ranking-${randomUUID()}.sqlite`);
    const writable = new Database(path);

    writable.exec(`
      CREATE TABLE snapshot (
        id INTEGER PRIMARY KEY,
        schema_version INTEGER NOT NULL,
        short_open_tag_enabled INTEGER NOT NULL
      );
      INSERT INTO snapshot VALUES (1, ${SUPPORTED_SCHEMA_VERSION}, 1);
      CREATE VIRTUAL TABLE fts_test USING fts5(content, tokenize='unicode61');
      CREATE TABLE meta (id INTEGER PRIMARY KEY, section TEXT NOT NULL);
    `);

    const insert = writable.prepare('INSERT INTO fts_test(content) VALUES (?)');
    const insertMeta = writable.prepare('INSERT INTO meta(id, section) VALUES (?, ?)');

    // Много сильных частичных совпадений моделируют регрессию старого
    // предварительного top-120. Полное совпадение намеренно очень длинное.
    for (let i = 0; i < 150; i++) insert.run('alpha alpha alpha alpha alpha');
    for (let i = 0; i < 150; i++) insert.run('beta beta beta beta beta');
    insert.run(`alpha beta ${'padding '.repeat(5_000)}`);

    const orm = insert.run('security cache').lastInsertRowid;
    const other = insert.run('security cache').lastInsertRowid;
    insertMeta.run(orm, 'orm');
    insertMeta.run(other, 'security');

    writable.close();
    db = new IndexDatabase(path);
  });

  after(() => {
    db?.close();
    rmSync(path, { force: true });
  });

  test('полное совпадение не теряется за предварительным top-N (FR-001)', () => {
    const rows = rankedSearch<{ content: string; matched: number }>(
      db,
      'fts_test',
      'f.content',
      ['alpha', 'beta'],
      [],
      [],
      [],
      5,
    );

    assert.equal(rows[0]?.matched, 2);
    assert.match(rows[0]?.content ?? '', /alpha beta/);
  });

  test('фильтр присоединённой таблицы применяется после FTS-отбора', () => {
    const rows = rankedSearch<{ section: string }>(
      db,
      'fts_test',
      'm.section',
      ['security'],
      ['JOIN meta m ON m.id = f.rowid'],
      ['m.section = ?'],
      ['orm'],
      5,
    );

    assert.deepEqual(rows.map((row) => row.section), ['orm']);
  });
});

describe('Ранжирование выдачи', { skip: !indexAvailable() }, () => {
  let db: IndexDatabase;

  before(() => {
    db = openIndex();
  });

  after(() => db?.close());

  function ranked(tokens: string[], limit = 30): Array<{ fqn: string; matched: number }> {
    // Прямой вызов общего модуля: проверяется сам порядок, а не обёртка ответа.
    return (
      rankedSearch<{ fqn: string; matched: number }>(
        db,
        'fts_code',
        'f.fqn',
        tokens,
        [],
        [],
        [],
        limit,
      ) ?? []
    );
  }

  test('число совпавших слов не возрастает вниз по выдаче (FR-001)', () => {
    const rows = ranked(['send', 'mail']);

    assert.ok(rows.length > 0, 'Контрольный запрос ничего не нашёл');

    for (let i = 1; i < rows.length; i++) {
      assert.ok(
        rows[i - 1].matched >= rows[i].matched,
        `Порядок нарушен на позиции ${i}: ${rows[i - 1].matched} < ${rows[i].matched}`,
      );
    }
  });

  test('многословный запрос ставит полные совпадения выше частичных', () => {
    const rows = ranked(['send', 'mail']);

    if (rows.length < 2) return;

    const best = Math.max(...rows.map((r) => r.matched));

    if (best <= 1) return; // в индексе нет полного совпадения — инвариант вырождается

    assert.ok(rows[0].matched === best, 'Первый результат не является полным совпадением');
  });

  test('одинаковый запрос даёт одинаковый порядок (FR-009)', () => {
    const first = ranked(['access', 'rights', 'user']);
    const second = ranked(['access', 'rights', 'user']);

    assert.deepEqual(first, second);
  });

  test('контрольный набор из 20 реальных запросов соблюдает полноту и fallback', () => {
    const fixturePath = join(import.meta.dirname, '..', 'fixtures', 'search-queries.json');
    const queries = JSON.parse(readFileSync(fixturePath, 'utf8')) as string[];

    assert.ok(queries.length >= 20);

    for (const query of queries) {
      const tokens = tokenizeQuery(query);
      const rows = ranked(tokens, 5);
      const orQuery = tokens.map((token) => `"${token}"`).join(' OR ');
      const andQuery = tokens.map((token) => `"${token}"`).join(' AND ');
      const partialExists = db.count(
        'SELECT COUNT(*) AS c FROM fts_code WHERE fts_code MATCH ?',
        [orQuery],
      );
      const fullExists = db.count(
        'SELECT COUNT(*) AS c FROM fts_code WHERE fts_code MATCH ?',
        [andQuery],
      );

      if (partialExists > 0) {
        assert.ok(rows.length > 0, `${query}: потеряны частичные совпадения`);
      }
      if (fullExists > 0) {
        assert.equal(rows[0]?.matched, tokens.length, `${query}: полное совпадение не первое`);
      }
    }
  });
});
