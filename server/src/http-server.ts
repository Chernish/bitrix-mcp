import { createServer } from 'node:http';
import type { IncomingMessage, ServerResponse, Server } from 'node:http';

import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';

import type { IndexDatabase } from './db/index.ts';
import { isAuthorized } from './http-auth.ts';
import { createMcpInstance } from './mcp.ts';

export const DEFAULT_BODY_LIMIT = 1024 * 1024;

export interface HttpMcpOptions {
  db: IndexDatabase;
  token: string;
  bodyLimit?: number;
  log?: (message: string) => void;
}

class HttpError extends Error {
  readonly status: number;

  constructor(status: number, message: string) {
    super(message);
    this.status = status;
  }
}

export function createHttpMcpServer(options: HttpMcpOptions): Server {
  const log = options.log ?? ((message: string) => process.stderr.write(message + '\n'));
  const server = createServer(
    { requestTimeout: 15_000, headersTimeout: 10_000, keepAliveTimeout: 5_000 },
    (req, res) => {
      void handleRequest(req, res, options).catch((error: unknown) => {
        const status = error instanceof HttpError ? error.status : 500;
        const message = error instanceof Error ? error.message : String(error);
        if (!(error instanceof HttpError)) {
          log('HTTP MCP: ' + message);
        }

        if (res.headersSent) {
          res.end();
          return;
        }

        sendJson(res, status, { error: status === 500 ? 'Internal server error' : message });
      });
    },
  );

  return server;
}

async function handleRequest(
  req: IncomingMessage,
  res: ServerResponse,
  options: HttpMcpOptions,
): Promise<void> {
  const path = (req.url ?? '/').split('?', 1)[0];
  if (path !== '/mcp') {
    throw new HttpError(404, 'Not found');
  }
  if (req.method !== 'POST') {
    res.setHeader('Allow', 'POST');
    throw new HttpError(405, 'Method not allowed');
  }
  if (!isAuthorized(options.token, req.headers.authorization)) {
    res.setHeader('WWW-Authenticate', 'Bearer realm="bitrix-mcp"');
    throw new HttpError(401, 'Unauthorized');
  }

  const contentType = req.headers['content-type'] ?? '';
  if (!contentType.toLowerCase().startsWith('application/json')) {
    throw new HttpError(400, 'Expected application/json');
  }
  const body = await readJson(req, options.bodyLimit ?? DEFAULT_BODY_LIMIT);

  const instance = createMcpInstance(options.db, options.log);
  const transport = new StreamableHTTPServerTransport({
    sessionIdGenerator: undefined,
    enableJsonResponse: true,
  });

  try {
    await instance.server.connect(transport);
    await transport.handleRequest(req, res, body);
  } finally {
    await transport.close();
    await instance.server.close();
  }
}

async function readJson(req: IncomingMessage, limit: number): Promise<unknown> {
  const chunks: Buffer[] = [];
  let size = 0;

  for await (const chunk of req) {
    const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
    size += buffer.length;
    if (size > limit) {
      throw new HttpError(413, 'Request body too large');
    }
    chunks.push(buffer);
  }

  if (size === 0) {
    throw new HttpError(400, 'Empty request body');
  }

  try {
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } catch {
    throw new HttpError(400, 'Malformed JSON');
  }
}

function sendJson(res: ServerResponse, status: number, payload: unknown): void {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    'Content-Type': 'application/json; charset=utf-8',
    'Content-Length': Buffer.byteLength(body),
  });
  res.end(body);
}
