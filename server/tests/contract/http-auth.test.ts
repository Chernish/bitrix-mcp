import { after, before, describe, test } from 'node:test';
import assert from 'node:assert/strict';
import type { Server } from 'node:http';

import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

import { createHttpMcpServer } from '../../src/http-server.ts';
import type { IndexDatabase } from '../../src/db/index.ts';
import { indexAvailable, openIndex } from '../helpers.ts';

const TOKEN = 'test-token-'.padEnd(64, 'x');

describe('HTTP MCP с Bearer-авторизацией', { skip: !indexAvailable() }, () => {
  let db: IndexDatabase;
  let server: Server;
  let url: URL;
  const logs: string[] = [];

  before(async () => {
    db = openIndex();
    server = createHttpMcpServer({
      db,
      token: TOKEN,
      bodyLimit: 1024,
      log: (message) => logs.push(message),
    });
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', () => resolve()));
    const address = server.address();
    assert.ok(typeof address === 'object' && address !== null);
    url = new URL(`http://127.0.0.1:${address.port}/mcp`);
  });

  after(async () => {
    await new Promise<void>((resolve) => server.close(() => resolve()));
    db.close();
  });

  async function post(body: string, token?: string, path = '/mcp'): Promise<Response> {
    const target = new URL(url);
    target.pathname = path;

    return fetch(target, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body,
    });
  }

  test('валидный токен открывает все инструменты и сохраняет snapshot provenance', async () => {
    const transport = new StreamableHTTPClientTransport(url, {
      requestInit: { headers: { Authorization: `Bearer ${TOKEN}` } },
    });
    const client = new Client({ name: 'http-contract-test', version: '1.0.0' });

    try {
      await client.connect(transport);
      const tools = await client.listTools();
      assert.equal(tools.tools.length, 23);

      const response = await client.callTool({ name: 'bitrix_index_status', arguments: {} });
      assert.notEqual(response.isError, true);
      const data = JSON.parse(response.content[0].text);
      assert.equal(data.result.snapshot_id, db.snapshotId);
      assert.equal(data.provenance.snapshot_id, db.snapshotId);
      assert.equal(data.result.sm_version, db.snapshot.sm_version);
    } finally {
      await client.close();
    }

    assert.equal(logs.join('\n').includes(TOKEN), false);
  });

  test('невалидные аргументы отклоняются до обработчика', async () => {
    const transport = new StreamableHTTPClientTransport(url, {
      requestInit: { headers: { Authorization: `Bearer ${TOKEN}` } },
    });
    const client = new Client({ name: 'http-validation-test', version: '1.0.0' });

    try {
      await client.connect(transport);

      for (const args of [{}, { fqn: 'IblockTable', limit: -1 }]) {
        const response = await client.callTool({ name: 'bitrix_get_class', arguments: args });
        assert.equal(response.isError, true);
        assert.match(response.content[0].text, /Некорректные аргументы/);
      }
    } finally {
      await client.close();
    }
  });

  test('отсутствующий и неверный токен отклоняются до разбора JSON', async () => {
    for (const token of [undefined, 'wrong']) {
      const response = await post('{not-json', token);
      assert.equal(response.status, 401);
      assert.match(response.headers.get('www-authenticate') ?? '', /Bearer/);
      assert.equal(await response.json().then((body) => body.error), 'Unauthorized');
    }
  });

  test('путь и метод ограничены endpoint MCP', async () => {
    const missing = await post('{}', TOKEN, '/other');
    assert.equal(missing.status, 404);

    const response = await fetch(url, { headers: { Authorization: `Bearer ${TOKEN}` } });
    assert.equal(response.status, 405);
    assert.equal(response.headers.get('allow'), 'POST');
  });

  test('повреждённый и слишком большой запрос не роняют сервер', async () => {
    const malformed = await post('{not-json', TOKEN);
    assert.equal(malformed.status, 400);

    const oversized = await post('x'.repeat(1025), TOKEN);
    assert.equal(oversized.status, 413);

    const alive = await post('{}', TOKEN);
    assert.notEqual(alive.status, 500);
  });
});
