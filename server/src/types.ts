/**
 * Общие типы ответа.
 *
 * Конверт `provenance` обязателен в КАЖДОМ успешном ответе. Это структурная
 * реализация Принципа II конституции: инструмент, забывший версию, не проходит
 * контрактный тест, а не полагается на внимательность ревьюера.
 */

export type SnapshotRole = 'active' | 'archive' | 'direct';

export interface Provenance {
  /** Модуль-владелец факта. Отсутствует, если выдача охватывает несколько модулей. */
  module?: string;
  /** Версия модуля. null означает, что версию установить не удалось. */
  module_version?: string | null;
  /**
   * Версию установить не удалось.
   * Подстановка версии сборки вместо неизвестной версии модуля ЗАПРЕЩЕНА:
   * она превращает честное незнание в уверенную ложь.
   */
  version_unknown?: boolean;
  /** Версия сборки из снимка. */
  sm_version: string | null;
  snapshot_id: string;
  snapshot_role: SnapshotRole;
  indexed_at: string;
  /** Индекс разошёлся с ядром. Выставляется на КАЖДОМ ответе, не только в диагностике. */
  stale: boolean;
  stale_reason: string | null;
  /**
   * Индекс построен при выключенном short_open_tag и потому неполон:
   * 41% файлов ядра теряется молча (research.md, R-001).
   */
  index_unusable?: boolean;
  index_unusable_reason?: string;
}

export interface Envelope<T> {
  provenance: Provenance;
  result: T;
}

export type NotFoundReason =
  | 'not_in_snapshot'
  | 'invalid_query'
  | 'ambiguous'
  | 'index_missing'
  | 'schema_mismatch';

export interface NotFound {
  found: false;
  reason: NotFoundReason;
  query: string;
  /**
   * Похожие имена. Отдельное поле с явной пометкой, что это ДОГАДКА,
   * а не результат: Принцип I запрещает подсовывать похожее вместо отказа.
   */
  did_you_mean?: { guess: true; names: string[] };
}

export interface SymbolRow {
  fqn: string;
  canonical_fqn: string;
  short_name: string;
  kind: string;
  module: string;
  module_version: string | null;
  version_unknown: boolean;
  parent_fqn: string | null;
  interfaces: string[];
  is_deprecated: boolean;
  deprecated_note: string | null;
  /** ЭВРИСТИКА по форме имени, не факт. См. ApiStyle в индексаторе. */
  api_style: string;
  api_style_is_heuristic: true;
  file: string;
  line: number;
  method_count: number;
  doc_summary: string | null;
}

/**
 * Метод в выдаче.
 *
 * Намеренно узкий набор полей. `visibility`, `is_static`, `return_type` и типы
 * параметров уже содержатся в `signature`, и дублировать их отдельными ключами
 * значит платить контекстом агента за одну и ту же информацию дважды.
 * Необязательные поля опускаются целиком, а не отдаются как null (Принцип IV).
 */
export interface MethodRow {
  name: string;
  signature: string;
  line: number;
  deprecated?: true;
  doc_summary?: string;
}

export interface ToolDefinition {
  name: string;
  description: string;
  inputSchema: Record<string, unknown>;
  handler: (args: Record<string, unknown>) => unknown;
}
