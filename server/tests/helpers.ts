import { existsSync, copyFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import Database from 'better-sqlite3';

import { IndexDatabase } from '../src/db/index.ts';
import { resolveSnapshotIndex } from '../src/snapshots.ts';
import { createRegistry } from '../src/tools/registry.ts';
import { resetStalenessCache } from '../src/staleness.ts';
import type { ToolDefinition } from '../src/types.ts';

/** Корень репозитория: тесты не должны зависеть от текущего каталога запуска. */
const REPO_ROOT = join(import.meta.dirname, '..', '..');

/**
 * Путь к индексу.
 *
 * Относительный BITRIX_MCP_INDEX разрешается от КОРНЯ РЕПОЗИТОРИЯ, а не от cwd.
 * Иначе `BITRIX_MCP_INDEX=./index.sqlite make test` даёт `server/index.sqlite`,
 * индекс «не находится», и все контрактные тесты тихо уходят в skip —
 * зелёный прогон, не проверивший ничего.
 */
export const INDEX_PATH = (() => {
  const configured = process.env.BITRIX_MCP_INDEX;

  if (!configured) return resolve(REPO_ROOT, 'index.sqlite');

  return resolve(REPO_ROOT, configured);
})();

let warned = false;

export function indexAvailable(): boolean {
  const available = existsSync(INDEX_PATH);

  // Пропуск обязан быть громким. Молчаливый skip неотличим от успеха,
  // а контрактные тесты защищают NON-NEGOTIABLE принципы.
  if (!available && !warned) {
    warned = true;
    process.stderr.write(
      `\n⚠️  КОНТРАКТНЫЕ ТЕСТЫ ПРОПУЩЕНЫ: индекс не найден по пути ${INDEX_PATH}\n` +
        `   Постройте его: make build\n` +
        `   Зелёный прогон без индекса НЕ означает, что инварианты соблюдены.\n\n`,
    );
  }

  return available;
}

export function openIndex(): IndexDatabase {
  resetStalenessCache();

  const resolution = resolveSnapshotIndex(INDEX_PATH);

  return new IndexDatabase(resolution.path, resolution);
}

export function registry(db: IndexDatabase): ToolDefinition[] {
  return createRegistry(db);
}

/**
 * Аргументы, на которых каждый инструмент отвечает успешно.
 *
 * Ключ — имя инструмента. Инструмент без записи здесь ЛОМАЕТ контрактные
 * тесты намеренно: добавляя инструмент, автор обязан описать, как его вызвать,
 * иначе инвариант просто перестанет его покрывать (а тест останется зелёным).
 */
export const HAPPY_ARGS: Record<string, Record<string, unknown>> = {
  bitrix_find_symbol: { name: 'IblockTable' },
  bitrix_get_class: { fqn: 'IblockTable' },
  bitrix_check_method: { class: 'IblockTable', method: 'getMap' },
  bitrix_index_status: {},
  bitrix_get_orm_entity: { entity: 'IblockTable' },
  bitrix_list_events: { module: 'sale' },
  bitrix_get_component: { name: 'bitrix:main.feedback' },
  bitrix_list_rest_methods: { module: 'calendar' },
  bitrix_search_docs: { query: 'как работать с ORM' },
  bitrix_search_code: { query: 'send mail' },
  bitrix_list_modules: { filter: 'iblock' },
  bitrix_get_module: { module: 'iblock' },
  bitrix_list_module_api: { module: 'iblock', name_filter: 'IblockTable' },
  bitrix_list_components: { module: 'main', name_filter: 'feedback' },
  bitrix_get_class_hierarchy: { class: 'IblockTable' },
  bitrix_get_class_constants: { class: 'IblockTable' },
  bitrix_find_implementations: { interface: 'Controllerable', limit: 5 },
  bitrix_list_data_managers: { module: 'iblock', name_filter: 'IblockTable' },
  bitrix_find_events: { name_filter: 'OnAfterIBlockElementAdd', limit: 5 },
  bitrix_find_callable: { name: 'getMap', module: 'iblock', kind: 'method', limit: 5 },
  bitrix_find_usages: { name: 'getMap', source_module: 'iblock', limit: 5 },
  bitrix_get_doc_page: { resource: 'pages/security/secure-cookies', length: 300 },
  bitrix_find_namespace: { namespace: 'Bitrix\\Main\\IO', limit: 5 },
};

/** Аргументы, на которых инструмент обязан ответить отказом, а не догадкой. */
export const MISSING_ARGS: Record<string, Record<string, unknown>> = {
  bitrix_find_symbol: { name: 'CTotallyMadeUpClassXyz' },
  bitrix_get_class: { fqn: 'CTotallyMadeUpClassXyz' },
  bitrix_check_method: { class: 'CTotallyMadeUpClassXyz', method: 'nope' },
  bitrix_get_orm_entity: { entity: 'CTotallyMadeUpTableXyz' },
  bitrix_list_events: { module: 'no_such_module_xyz' },
  bitrix_get_component: { name: 'bitrix:no.such.component.xyz' },
  bitrix_list_rest_methods: { module: 'no_such_module_xyz' },
  bitrix_search_docs: { query: 'кверти йцукенг зжщхъфы' },
  bitrix_search_code: { query: 'йцукенг зжщхъфы квертиасд' },
  bitrix_get_module: { module: 'no_such_module_xyz' },
  bitrix_get_class_hierarchy: { class: 'CTotallyMadeUpClassXyz' },
  bitrix_get_class_constants: { class: 'CTotallyMadeUpClassXyz' },
  bitrix_find_implementations: { interface: 'TotallyMadeUpInterfaceXyz' },
  bitrix_find_callable: { name: 'totallyMadeUpCallableXyz' },
  bitrix_find_usages: { name: 'totallyMadeUpUsageXyz' },
  bitrix_get_doc_page: { resource: 'pages/no/such/resource-xyz' },
  bitrix_find_namespace: { namespace: 'Totally\\MadeUp\\NamespaceXyz' },
};

/** Копия индекса с подменённым sm_version — для проверки детекта рассинхрона. */
export function makeStaleIndexCopy(): string {
  const copy = join(tmpdir(), `bitrix-mcp-stale-${process.pid}.sqlite`);
  rmSync(copy, { force: true });
  copyFileSync(INDEX_PATH, copy);

  const writable = new Database(copy);
  writable.exec("UPDATE snapshot SET sm_version = '0.0.0-подменено' WHERE id = 1");
  writable.close();

  return copy;
}

export function cleanup(path: string): void {
  rmSync(path, { force: true });
}

/** Рекурсивный обход значений ответа — для проверок над всей выдачей. */
export function walkStrings(value: unknown, visit: (s: string) => void): void {
  if (typeof value === 'string') {
    visit(value);
    return;
  }

  if (Array.isArray(value)) {
    for (const item of value) walkStrings(item, visit);
    return;
  }

  if (value !== null && typeof value === 'object') {
    for (const item of Object.values(value)) walkStrings(item, visit);
  }
}
