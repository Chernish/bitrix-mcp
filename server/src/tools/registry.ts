import type { IndexDatabase } from '../db/index.ts';
import type { ToolDefinition } from '../types.ts';
import { findSymbolTool } from './find-symbol.ts';
import { getClassTool } from './get-class.ts';
import { checkMethodTool } from './check-method.ts';
import { indexStatusTool } from './index-status.ts';
import { ormEntityTool } from './orm-entity.ts';
import { listEventsTool } from './list-events.ts';
import { getComponentTool } from './get-component.ts';
import { restMethodsTool } from './rest-methods.ts';
import { searchDocsTool } from './search-docs.ts';
import { searchCodeTool } from './search-code.ts';
import { listModulesTool } from './list-modules.ts';
import { getModuleTool } from './get-module.ts';
import { listModuleApiTool } from './list-module-api.ts';
import { listComponentsTool } from './list-components.ts';
import { getClassHierarchyTool } from './get-class-hierarchy.ts';
import { getClassConstantsTool } from './get-class-constants.ts';
import { findImplementationsTool } from './find-implementations.ts';
import { listDataManagersTool } from './list-data-managers.ts';
import { findEventsTool } from './find-events.ts';
import { findCallableTool } from './find-callable.ts';
import { findUsagesTool } from './find-usages.ts';
import { getDocPageTool } from './get-doc-page.ts';
import { findNamespaceTool } from './find-namespace.ts';

/**
 * Единый реестр инструментов.
 *
 * Это ЕДИНСТВЕННЫЙ источник списка — и для регистрации в MCP, и для
 * контрактных тестов. Разделение двух списков привело бы к тому, что тест
 * инварианта «каждый инструмент возвращает provenance» остаётся зелёным,
 * перестав покрывать инструменты, добавленные позже. Такой тест хуже
 * отсутствующего: он создаёт видимость защиты NON-NEGOTIABLE Принципа II.
 *
 * Добавляя инструмент, добавляйте его СЮДА — и он автоматически попадёт
 * под все контрактные проверки.
 */
export function createRegistry(db: IndexDatabase): ToolDefinition[] {
  return [
    findSymbolTool(db),
    getClassTool(db),
    checkMethodTool(db),
    ormEntityTool(db),
    listEventsTool(db),
    getComponentTool(db),
    restMethodsTool(db),
    searchDocsTool(db),
    searchCodeTool(db),
    listModulesTool(db),
    getModuleTool(db),
    listModuleApiTool(db),
    listComponentsTool(db),
    getClassHierarchyTool(db),
    getClassConstantsTool(db),
    findImplementationsTool(db),
    listDataManagersTool(db),
    findEventsTool(db),
    findCallableTool(db),
    findUsagesTool(db),
    getDocPageTool(db),
    findNamespaceTool(db),
    indexStatusTool(db),
  ];
}

export function toolByName(registry: ToolDefinition[], name: string): ToolDefinition | undefined {
  return registry.find((t) => t.name === name);
}
