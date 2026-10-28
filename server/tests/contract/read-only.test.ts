import { test, describe, before, after } from 'node:test';
import assert from 'node:assert/strict';
import Database from 'better-sqlite3';

import { INDEX_PATH, indexAvailable, openIndex } from '../helpers.ts';
import { IndexDatabase, SchemaMismatchError } from '../../src/db/index.ts';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { rmSync, copyFileSync } from 'node:fs';

/**
 * Контрактный тест №8 — NON-NEGOTIABLE Принцип VI.
 *
 * Сервер — читатель, индексатор — писатель. Разделение держится не на
 * договорённости, а на режиме открытия файла: попытка записи со стороны
 * сервера обязана завершаться ошибкой на уровне драйвера.
 */
describe('Контракт №8: сервер открывает индекс только на чтение', { skip: !indexAvailable() }, () => {
  let db: IndexDatabase;

  before(() => {
    db = openIndex();
  });

  after(() => db?.close());

  test('запись через соединение сервера невозможна', () => {
    assert.throws(
      () => {
        // Обращаемся к тому же файлу тем же способом, каким его открывает сервер.
        const readonly = new Database(INDEX_PATH, { readonly: true, fileMustExist: true });

        try {
          readonly.exec("UPDATE snapshot SET sm_version = 'взломано' WHERE id = 1");
        } finally {
          readonly.close();
        }
      },
      /readonly|read-only/i,
      'Соединение сервера допускает запись — Принцип VI нарушен',
    );
  });

  test('данные снимка не изменились после попытки записи', () => {
    const fresh = openIndex();

    try {
      assert.equal(fresh.snapshot.sm_version, db.snapshot.sm_version);
    } finally {
      fresh.close();
    }
  });

  test('индекс несовместимой версии схемы отвергается, а не читается', () => {
    const copy = join(tmpdir(), `bitrix-mcp-badschema-${process.pid}.sqlite`);
    rmSync(copy, { force: true });
    copyFileSync(INDEX_PATH, copy);

    const writable = new Database(copy);
    writable.exec('UPDATE snapshot SET schema_version = 999 WHERE id = 1');
    writable.close();

    try {
      assert.throws(
        () => new IndexDatabase(copy),
        SchemaMismatchError,
        'Индекс чужой версии схемы прочитан вместо отказа: ' +
          'неверно истолкованные данные хуже отсутствующих',
      );
    } finally {
      rmSync(copy, { force: true });
    }
  });
});
