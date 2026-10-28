# Contract: MCP-инструменты

**Транспорт**: stdio. stdout принадлежит исключительно протоколу; вся диагностика идёт в stderr (R-012).

## Общий конверт ответа

Каждый успешный ответ любого инструмента содержит блок `provenance`. Это структурная реализация Принципа II: инструмент, забывший версию, не проходит контрактный тест.

```jsonc
{
  "provenance": {
    "module": "crm",                    // модуль-владелец факта
    "module_version": "24.1300.0",      // null => version_unknown = true
    "version_unknown": false,           // версию установить не удалось
    "sm_version": "25.100.300",         // версия сборки из снимка
    "indexed_at": "2026-08-12T10:00:00Z",
    "stale": false,                     // индекс разошёлся с ядром
    "stale_reason": null                // например "sm_version изменился: 25.100.300 -> 25.200.0"
  },
  "result": { /* специфично для инструмента */ }
}
```

Когда результат охватывает несколько модулей, `provenance.module` отсутствует, а модуль и версия указываются на каждом элементе выдачи.

**Обязательные инварианты**:

- `stale = true` MUST выставляться на **каждом** ответе, а не только в диагностическом инструменте. Агент, не спросивший о состоянии индекса, обязан узнать об устаревании из самого факта.
- `module_version = null` MUST сопровождаться `version_unknown = true`. Подстановка `sm_version` вместо неизвестной версии модуля запрещена.

## Общий формат отказа

```jsonc
{ "provenance": { ... }, "result": { "found": false, "reason": "not_in_snapshot", "query": "CCrmDealX" } }
```

`reason`: `not_in_snapshot` | `ambiguous` | `index_missing` | `schema_mismatch`.

Отказ — нормальный, а не исключительный ответ (Принцип I). Инструмент MUST NOT предлагать «похожие» имена как результат; допустимо отдельное поле `did_you_mean` с явной пометкой, что это догадка, а не факт.

---

## 1. `bitrix_find_symbol`

Поиск класса, интерфейса, трейта по имени. Основная точка входа агента.

**Вход**: `name` (обязательно; короткое или полное имя), `module` (фильтр), `kind`, `limit` (по умолчанию 10).

**Поведение**:
- Регистронезависимый поиск сначала по точному совпадению короткого имени, затем полного, затем префиксный.
- MUST разрешать псевдонимы `class_alias` и возвращать обе формы (R-006).
- При совпадении в нескольких модулях возвращаются все, отсортированные по релевантности; усечение MUST быть явным (`truncated: true`, `total`).

**Выход**: массив `{ fqn, canonical_fqn, short_name, kind, module, module_version, parent_fqn, interfaces[], is_deprecated, deprecated_note, api_style, file, line, method_count, doc_summary }`.

`canonical_fqn` отличается от `fqn`, если запрос попал в псевдоним. `doc_summary` — первая строка PHPDoc, а не весь блок (Принцип IV).

---

## 2. `bitrix_get_class`

Состав класса: методы и сигнатуры.

**Вход**: `fqn` (обязательно), `visibility` (по умолчанию `public`), `include_inherited` (по умолчанию `false`), `name_filter`, `limit` (по умолчанию 40), `offset`.

**Выход**: `{ fqn, kind, module, module_version, parent_fqn, is_deprecated, api_style, methods: [{ name, signature, visibility, is_static, return_type, line, doc_summary, deprecated }], total, truncated }`.

Значение `limit` по умолчанию выбрано под классы старого API с десятками методов: полная выдача такого класса вытеснила бы из контекста агента саму задачу (Принцип IV). Усечение всегда явное.

---

## 3. `bitrix_get_orm_entity`

**Вход**: `entity` (обязательно; FQN класса или имя таблицы), `include_references` (по умолчанию `true`).

**Выход**: `{ fqn, table_name, module, module_version, is_complete, incomplete_reason, extraction_style, fields: [{ name, data_type, is_primary, is_required, is_autocomplete, default, default_resolved, values, reference: { target_fqn, join } }] }`.

`is_complete = false` MUST сопровождаться `incomplete_reason` — например «значения по умолчанию заданы замыканиями, статически не вычислимы». Отдавать частичную схему как полную запрещено (Принцип III).

---

## 4. `bitrix_list_events`

**Вход**: `module` (обязательно), `kind` (`send` | `register` | `any`, по умолчанию `send`), `name_filter`, `limit` (по умолчанию 50).

**Выход**: `{ module, module_version, events: [{ name, kind, call_style, file, line, params }], total, truncated }`.

---

## 5. `bitrix_get_component`

**Вход**: `name` (обязательно, вид `bitrix:main.feedback`), `params_only` (по умолчанию `true`).

**Выход**: `{ full_name, module, module_version, title, description, params: [{ code, type, name, default, default_resolved, parent_group, multiple, values }], unresolved_count }`.

`unresolved_count` показывает, сколько значений вычисляются в рантайме и потому недоступны без выполнения кода (R-004, Принцип VI). Это ожидаемое состояние, а не ошибка.

---

## 6. `bitrix_check_method`

Прямая проверка существования — самый дешёвый способ погасить галлюцинацию.

**Вход**: `class` (обязательно), `method` (обязательно).

**Выход**: `{ exists: bool, class_exists: bool, fqn, canonical_fqn, module, module_version, signature, is_static, visibility, is_deprecated, file, line }`.

Различение `exists` и `class_exists` даёт агенту точный диагноз: неверный класс или неверный метод в верном классе.

---

## 7. `bitrix_search_code`

Поиск по описанию задачи, когда точное имя неизвестно.

**Вход**: `query` (обязательно), `module`, `kind` (`symbol` | `method` | `any`), `limit` (по умолчанию 15).

**Выход**: массив `{ fqn, method, module, module_version, signature, file, line, snippet, score }`.

`snippet` — фрагмент PHPDoc, не содержимое файла.

---

## 8. `bitrix_search_docs`

**Вход**: `query` (обязательно, поддержка кириллицы обязательна — FR-032), `section`, `limit` (по умолчанию 5).

**Выход**: массив `{ title, section, url, excerpt, version_notes[], source_ref, score }`
плюс `docs_crawled_at`, `docs_source` и `docs_source_revision`.

**Инвариант расхождения** (FR-030, Принцип I): если документация утверждает существование символа, отсутствующего в индексе кода, ответ MUST содержать `code_conflict: { symbol, doc_claims: true, present_in_snapshot: false }`. Приоритет у кода, но расхождение обязано быть видимым.

---

## 9. `bitrix_list_rest_methods`

**Вход**: `module`, `name_filter`, `limit` (по умолчанию 50).
**Выход**: `{ methods: [{ name, module, module_version, handler, file, line }], total, truncated }`.

---

## 10. `bitrix_index_status`

Диагностика. Не заменяет флаг `stale` в остальных ответах, а дополняет его подробностями.

**Вход**: `deep` (по умолчанию `false` — сверка только версии сборки; `true` — перечитывание карты версий всех модулей).

**Выход**:

```jsonc
{
  "core_root": "...", "sm_version": "25.100.300", "sm_version_date": "2025-03-21",
  "indexed_at": "...", "schema_version": 1,
  "short_open_tag_enabled": true,
  "counts": { "modules": 99, "files": 67836, "symbols": 0, "methods": 0,
              "orm_entities": 0, "events": 0, "components": 0, "doc_pages": 112 },
  "parse_errors": { "count": 0, "share": 0.0, "sample": [] },
  "stale": false, "stale_reason": null,
  "module_versions": { "crm": "24.1300.0", "rest": "25.100.200" },   // при deep = true
  "modules_without_version": []
}
```

`short_open_tag_enabled = false` MUST трактоваться сервером как непригодный индекс: он построен в режиме, при котором 41% файлов ядра теряется молча (R-001). Сервер MUST сообщать об этом при каждом запросе, а не только здесь.

---

## Контрактные тесты

Обязательны к реализации, проверяют инварианты, а не примеры:

1. Каждый инструмент на успешном ответе возвращает непустые `provenance.module_version` либо `version_unknown = true`. Нарушение — дефект (Принцип II).
2. Ни один инструмент не возвращает содержимое файла целиком (Принцип IV).
3. Запрос заведомо несуществующего имени даёт `found: false` без подстановки похожих в основной результат (Принцип I).
4. Запрос по псевдониму `\Bitrix\Sale\OrderTable` находит класс и возвращает `canonical_fqn` (R-006).
5. Запрос класса, объявленного в файле с коротким тегом `<?`, находит его (регрессия R-001).
6. При подменённом `sm_version` все инструменты выставляют `stale = true`.
7. Усечённая выдача всегда сопровождается `truncated: true` и `total`.
8. Запись в файл индекса со стороны сервера невозможна (Принцип VI).
