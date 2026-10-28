import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';

import { indexAvailable, openIndex, registry } from '../helpers.ts';
import type { IndexDatabase } from '../../src/db/index.ts';
import type { Envelope } from '../../src/types.ts';

describe('Расширенная навигация API', { skip: !indexAvailable() }, () => {
  let db: IndexDatabase;

  before(() => {
    db = openIndex();
  });

  after(() => db?.close());

  function call(name: string, args: Record<string, unknown>): Envelope<Record<string, unknown>> {
    const tool = registry(db).find((candidate) => candidate.name === name);
    assert.ok(tool, `нет инструмента ${name}`);

    return tool.handler(args) as Envelope<Record<string, unknown>>;
  }

  test('реестр содержит 23 инструмента', () => {
    assert.equal(registry(db).length, 23);
  });

  test('модуль возвращает версию, зависимости и счётчики API', () => {
    const response = call('bitrix_get_module', { module: 'iblock' });
    assert.equal(response.provenance.module, 'iblock');
    assert.ok(response.provenance.module_version);
    assert.equal(response.result.found, true);
    assert.ok((response.result.counts as { symbols: number }).symbols > 0);
  });

  test('константы IblockTable извлечены из AST', () => {
    const response = call('bitrix_get_class_constants', {
      class: 'IblockTable',
      name_filter: 'TYPE_TEXT',
    });
    const constants = response.result.constants as Array<{ name: string; value: string }>;
    assert.deepEqual(constants.map((item) => item.name), ['TYPE_TEXT']);
    assert.equal(constants[0]?.value, "'text'");
  });

  test('точный callable-поиск уважает фильтр модуля', () => {
    const response = call('bitrix_find_callable', {
      name: 'getMap',
      module: 'iblock',
      kind: 'method',
      limit: 20,
    });
    const items = response.result.items as Array<{ module: string; signature: string }>;
    assert.ok(items.length > 0);
    assert.ok(items.every((item) => item.module === 'iblock'));
    assert.ok(items.every((item) => item.signature.includes('function getMap')));
  });

  test('поиск usages возвращает файл и строку', () => {
    const response = call('bitrix_find_usages', {
      name: 'getMap',
      source_module: 'iblock',
      limit: 5,
    });
    const usages = response.result.usages as Array<{ file: string; line: number }>;
    assert.ok(usages.length > 0);
    assert.ok(usages.every((usage) => usage.file.startsWith('modules/iblock/')));
    assert.ok(usages.every((usage) => usage.line > 0));
  });

  test('встроенный PHP-интерфейс находится по факту implements', () => {
    const response = call('bitrix_find_implementations', { interface: 'ArrayAccess', limit: 3 });
    assert.equal(response.result.found, true);
    assert.equal(response.result.declaration_in_snapshot, false);
    assert.ok((response.result.implementations as unknown[]).length > 0);
  });

  test('поиск namespace не смешивает соседние префиксы', () => {
    const response = call('bitrix_find_namespace', { namespace: 'Bitrix\\Main\\IO', limit: 20 });
    const items = response.result.items as Array<{ fqn: string }>;
    assert.ok(items.length > 0);
    assert.ok(items.every((item) => item.fqn.startsWith('\\Bitrix\\Main\\IO\\')));
  });

  test('страница документа читается порциями и с version notes', () => {
    const response = call('bitrix_get_doc_page', {
      resource: 'pages/security/secure-cookies',
      length: 300,
    });
    assert.equal(response.result.found, true);
    assert.equal(response.result.truncated, true);
    const notes = response.result.version_notes as Array<{ since: string }>;
    assert.ok(notes.some((note) => note.since === '20.5.400'));
  });
});
