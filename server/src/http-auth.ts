import { createHash, timingSafeEqual } from 'node:crypto';

export function requireHttpToken(token: string | undefined): string {
  if (!token || Buffer.byteLength(token, 'utf8') < 32) {
    throw new Error(
      'HTTP MCP требует BITRIX_MCP_TOKEN длиной не менее 32 байт. ' +
      'Сгенерируйте: openssl rand -hex 32',
    );
  }

  return token;
}

export function bearerToken(header: string | string[] | undefined): string | null {
  if (typeof header !== 'string') return null;
  const match = /^Bearer ([^\s]+)$/i.exec(header);

  return match?.[1] ?? null;
}

export function isAuthorized(expectedToken: string, header: string | string[] | undefined): boolean {
  const presented = bearerToken(header);
  if (presented === null) return false;

  const expected = createHash('sha256').update(expectedToken).digest();
  const actual = createHash('sha256').update(presented).digest();

  return timingSafeEqual(expected, actual);
}
