import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { resolve } from 'node:path';

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StdioClientTransport } from '@modelcontextprotocol/sdk/client/stdio.js';

import { INDEX_PATH, indexAvailable } from '../helpers.ts';

describe('Валидация аргументов MCP', { skip: !indexAvailable() }, () => {
  let client: Client;

  before(async () => {
    const transport = new StdioClientTransport({
      command: process.execPath,
      args: ['--experimental-strip-types', resolve(import.meta.dirname, '../../src/index.ts')],
      env: { ...process.env, BITRIX_MCP_INDEX: INDEX_PATH },
      stderr: 'pipe',
    });
    client = new Client({ name: 'stdio-validation-test', version: '1.0.0' });
    await client.connect(transport);
  });

  after(async () => {
    await client?.close();
  });

  test('listTools публикует ограничения пагинации', async () => {
    const response = await client.listTools();
    const tool = response.tools.find((candidate) => candidate.name === 'bitrix_get_class');
    assert.ok(tool);
    const properties = tool.inputSchema.properties as Record<string, Record<string, unknown>>;
    assert.equal(tool.inputSchema.additionalProperties, false);
    assert.equal(properties.limit?.type, 'integer');
    assert.equal(properties.limit?.minimum, 1);
    assert.equal(properties.limit?.maximum, 100);
    assert.equal(properties.offset?.minimum, 0);
  });

  test('ошибки аргументов возвращаются вместо случайных данных', async () => {
    const cases: Array<{ args: Record<string, unknown>; expected: RegExp }> = [
      { args: {}, expected: /обязательное поле "fqn"/ },
      { args: { fqn: 'IblockTable', limit: -1 }, expected: /не меньше 1/ },
      { args: { fqn: 'IblockTable', limit: 1.5 }, expected: /тип integer/ },
      { args: { fqn: 'IblockTable', limit: '10' }, expected: /тип integer/ },
      { args: { fqn: 'IblockTable', offset: -1 }, expected: /не меньше 0/ },
      { args: { fqn: 'IblockTable', visibility: 'unknown' }, expected: /недопустимое значение/ },
      { args: { fqn: 'IblockTable', unexpected: true }, expected: /неизвестное поле/ },
    ];

    for (const item of cases) {
      const response = await client.callTool({ name: 'bitrix_get_class', arguments: item.args });
      assert.equal(response.isError, true, JSON.stringify(item.args));
      assert.match(response.content[0].text, item.expected);
    }
  });

  test('валидная вторая страница продолжает работать', async () => {
    const response = await client.callTool({
      name: 'bitrix_get_class',
      arguments: { fqn: 'IblockTable', limit: 2, offset: 2 },
    });
    assert.notEqual(response.isError, true);
    const data = JSON.parse(response.content[0].text);
    assert.equal(data.result.found, true);
    assert.equal(data.result.methods.length, 2);
    assert.equal(data.result.truncated, true);
  });
});
