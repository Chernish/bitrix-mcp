import { Server } from '@modelcontextprotocol/sdk/server/index.js';
import {
  CallToolRequestSchema,
  ListToolsRequestSchema,
} from '@modelcontextprotocol/sdk/types.js';

import type { IndexDatabase } from './db/index.ts';
import { createRegistry, toolByName } from './tools/registry.ts';
import { validateToolArguments } from './validation.ts';

export interface McpInstance {
  server: Server;
  toolCount: number;
}

export function createMcpInstance(
  db: IndexDatabase,
  log: (message: string) => void = (message) => process.stderr.write(message + '\n'),
): McpInstance {
  const registry = createRegistry(db);
  const server = new Server(
    { name: 'bitrix-mcp', version: '0.1.0' },
    { capabilities: { tools: {} } },
  );

  server.setRequestHandler(ListToolsRequestSchema, () => ({
    tools: registry.map((tool) => ({
      name: tool.name,
      description: tool.description,
      inputSchema: tool.inputSchema,
    })),
  }));

  server.setRequestHandler(CallToolRequestSchema, (request) => {
    const tool = toolByName(registry, request.params.name);

    if (!tool) {
      return {
        isError: true,
        content: [{ type: 'text' as const, text: `Неизвестный инструмент: ${request.params.name}` }],
      };
    }

    const args = request.params.arguments ?? {};
    const validationError = validateToolArguments(tool.inputSchema, args);

    if (validationError) {
      return {
        isError: true,
        content: [
          { type: 'text' as const, text: `Некорректные аргументы ${tool.name}: ${validationError}` },
        ],
      };
    }

    try {
      const result = tool.handler(args);

      return {
        content: [{ type: 'text' as const, text: JSON.stringify(result) }],
      };
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      log(`ошибка в ${tool.name}: ${message}`);

      return {
        isError: true,
        content: [{ type: 'text' as const, text: `Ошибка: ${message}` }],
      };
    }
  });

  return { server, toolCount: registry.length };
}
