import { describe, test } from 'node:test';
import assert from 'node:assert/strict';

import { bearerToken, isAuthorized, requireHttpToken } from '../../src/http-auth.ts';

const TOKEN = 'a'.repeat(64);

describe('Bearer-авторизация HTTP MCP', () => {
  test('HTTP-токен обязателен и имеет безопасную минимальную длину', () => {
    assert.equal(requireHttpToken(TOKEN), TOKEN);
    assert.throws(() => requireHttpToken(undefined), /BITRIX_MCP_TOKEN/);
    assert.throws(() => requireHttpToken(''), /BITRIX_MCP_TOKEN/);
    assert.throws(() => requireHttpToken('short'), /32/);
  });

  test('Authorization разбирается только как один Bearer-токен', () => {
    assert.equal(bearerToken(`Bearer ${TOKEN}`), TOKEN);
    assert.equal(bearerToken(`bearer ${TOKEN}`), TOKEN);
    assert.equal(bearerToken(`Basic ${TOKEN}`), null);
    assert.equal(bearerToken(`Bearer  ${TOKEN}`), null);
    assert.equal(bearerToken(`Bearer ${TOKEN} extra`), null);
    assert.equal(bearerToken([`Bearer ${TOKEN}`]), null);
    assert.equal(bearerToken(undefined), null);
  });

  test('только точное совпадение принимается', () => {
    assert.equal(isAuthorized(TOKEN, `Bearer ${TOKEN}`), true);
    assert.equal(isAuthorized(TOKEN, `Bearer ${TOKEN}x`), false);
    assert.equal(isAuthorized(TOKEN, 'Bearer wrong'), false);
    assert.equal(isAuthorized(TOKEN, undefined), false);
  });
});
