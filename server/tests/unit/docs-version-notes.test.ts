import { describe, test } from 'node:test';
import assert from 'node:assert/strict';

import { parseVersionNotes } from '../../src/tools/search-docs.ts';

describe('Версионные примечания официальной документации', () => {
  test('версия модуля сверяется с локальным снимком', () => {
    const raw = JSON.stringify({
      schema: 1,
      notes: [
        {
          version: '23.0.0',
          subject_kind: 'module',
          module: 'main',
          subject: 'Главный модуль',
          heading: 'HTTP-клиент',
          statement: 'С версии Главного модуля 23.0.0 доступен PSR-18.',
        },
        {
          version: '26.0.0',
          subject_kind: 'module',
          module: 'ui',
          statement: 'Доступно с версии модуля UI 26.0.0.',
        },
      ],
    });

    const notes = parseVersionNotes(raw, new Map([['main', '25.100.300'], ['ui', '25.0.0']]));

    assert.equal(notes[0]?.availability, 'available');
    assert.equal(notes[0]?.installed_module_version, '25.100.300');
    assert.equal(notes[0]?.heading, 'HTTP-клиент');
    assert.equal(notes[1]?.availability, 'requires_newer_module');
  });

  test('PHP и неизвестный субъект не сравниваются с версией ядра', () => {
    const raw = JSON.stringify({
      schema: 1,
      notes: [{ version: '8.0', subject_kind: 'php', subject: 'PHP' }],
    });

    const [note] = parseVersionNotes(raw, new Map([['main', '25.100.300']]));

    assert.equal(note?.availability, 'unknown');
    assert.equal(note?.installed_module_version, undefined);
  });

  test('старый CSV читается как справочное упоминание', () => {
    const notes = parseVersionNotes('20.5.200, 24.100.0', new Map());

    assert.deepEqual(notes.map((note) => note.since), ['20.5.200', '24.100.0']);
    assert.ok(notes.every((note) => note.availability === 'unknown'));
  });
});
