# Phase 1: Data Model — индекс ядра 1С-Битрикс

**Дата**: 2026-08-12
**Хранилище**: активный файл SQLite и независимые SQLite-файлы архивных снимков в `<index>.snapshots/`. Внутри каждого файла одна строка `snapshot`; каталог версий хранится в `catalog.json`, проверяемый активный ID — в `active.json`. Индексатор — единственный писатель, сервер — только читатель (Принцип VI).

## Принципы схемы

1. **Версионная атрибуция вшита в структуру.** Каждая содержательная сущность связана с `module` через `module_id`, а `module` хранит версию. Отдать факт без версии структурно невозможно — это реализация Принципа II на уровне схемы, а не на уровне дисциплины кода.
2. **Неполнота выражена явно.** Везде, где извлечение может быть частичным, есть колонка-признак (`is_resolved`, `is_complete`, `*_source`). Отсутствие значения и невозможность его вычислить — разные состояния (Принцип III).
3. **Догадки отделены от фактов.** `is_deprecated` (из PHPDoc) и `api_style` (эвристика имени) — разные колонки, потому что имеют разную достоверность.
4. **Всё привязано к файлу.** `file_id` есть у каждой извлечённой записи, что даёт каскадное удаление при инкрементальной переиндексации одним действием.

---

## Ядро схемы

### `snapshot` — состояние индексируемой сборки

Одна строка в каждом SQLite-снимке. Метаданные, попадающие в каждый ответ сервера. Уникальный `snapshot_id` и роль снимка добавляются из каталога версий, поэтому схему таблиц менять не требуется.

| Колонка | Тип | Назначение |
|---|---|---|
| `id` | INTEGER PK | всегда 1 |
| `core_root` | TEXT NOT NULL | абсолютный путь корня ядра |
| `sm_version` | TEXT NOT NULL | версия сборки, например `25.100.300` |
| `sm_version_date` | TEXT | дата версии сборки |
| `indexed_at` | TEXT NOT NULL | момент завершения индексации, ISO-8601 |
| `indexer_version` | TEXT NOT NULL | версия индексатора, писавшего файл |
| `schema_version` | INTEGER NOT NULL | версия схемы; сервер отказывается читать чужую |
| `file_count` | INTEGER | сколько файлов учтено |
| `total_bytes` | INTEGER | суммарный объём |
| `parse_error_count` | INTEGER | сколько файлов не разобрано |
| `short_open_tag_enabled` | INTEGER NOT NULL | зафиксированное состояние настройки на момент индексации |
| `docs_crawled_at` | TEXT | момент последнего сбора документации, NULL если не собиралась |

`short_open_tag_enabled` хранится намеренно: индекс, построенный при выключенной настройке, недостоверен (R-001), и сервер должен уметь это обнаружить постфактум, а не полагаться на то, что индексатор не запустили неправильно.

### `module` — единица версионирования

| Колонка | Тип | Назначение |
|---|---|---|
| `id` | INTEGER PK | |
| `code` | TEXT NOT NULL UNIQUE | `crm`, `iblock`, `main` |
| `version` | TEXT | `24.1300.0`; NULL если не удалось установить |
| `version_date` | TEXT | дата версии |
| `version_source` | TEXT NOT NULL | `module_file` \| `sm_version` \| `unknown` |
| `rel_path` | TEXT NOT NULL | путь относительно корня |

`version_source` разделяет три случая: версия прочитана из `install/version.php` модуля; модуль — `main`, и версия взята из константы сборки; версию установить не удалось. Третий случай MUST отдаваться как «версия неизвестна» и MUST NOT подменяться версией сборки (Принцип II).

### `file` — единица инкрементальности

| Колонка | Тип | Назначение |
|---|---|---|
| `id` | INTEGER PK | |
| `module_id` | INTEGER FK → module | |
| `rel_path` | TEXT NOT NULL UNIQUE | путь относительно `core_root` |
| `size` | INTEGER NOT NULL | быстрый фильтр |
| `mtime` | INTEGER NOT NULL | быстрый фильтр |
| `sha256` | TEXT NOT NULL | арбитр изменения |
| `parsed_ok` | INTEGER NOT NULL | 0 при ошибке разбора |
| `parse_error` | TEXT | текст ошибки |
| `had_short_open_tag` | INTEGER NOT NULL | признак для диагностики R-001 |
| `seen_in_pass` | INTEGER NOT NULL | служебная метка обхода для удаления исчезнувших |

Хранится путь **относительно** корня, а не абсолютный: это делает файл индекса переносимым (SC-006).

---

## Символы и сигнатуры

### `symbol` — класс, интерфейс, трейт, перечисление

| Колонка | Тип | Назначение |
|---|---|---|
| `id` | INTEGER PK | |
| `module_id`, `file_id` | INTEGER FK | атрибуция и каскад |
| `fqn` | TEXT NOT NULL | полное имя с пространством |
| `fqn_lower` | TEXT NOT NULL | для регистронезависимого лукапа |
| `short_name` | TEXT NOT NULL | имя без пространства |
| `short_name_lower` | TEXT NOT NULL | основной путь поиска агента |
| `kind` | TEXT NOT NULL | `class` \| `interface` \| `trait` \| `enum` |
| `parent_fqn` | TEXT | родитель после резолва |
| `is_abstract`, `is_final` | INTEGER | |
| `is_deprecated` | INTEGER NOT NULL | **факт** из `@deprecated` |
| `deprecated_note` | TEXT | текст пометки, часто содержит замену |
| `api_style` | TEXT NOT NULL | **эвристика**: `d7` \| `legacy` \| `unknown` |
| `doc_comment` | TEXT | PHPDoc |
| `line` | INTEGER NOT NULL | строка объявления |

`is_deprecated` и `api_style` разделены сознательно (R-007): первое — прочитанный факт, второе — вывод по форме имени.

### `symbol_interface`

Связь многие-ко-многим: `symbol_id`, `interface_fqn`. Отдельная таблица, потому что интерфейсов у класса может быть несколько.

### `symbol_alias` — псевдонимы `class_alias`

| Колонка | Тип | Назначение |
|---|---|---|
| `alias_fqn`, `alias_fqn_lower` | TEXT NOT NULL | имя, которым пользуются |
| `target_fqn` | TEXT NOT NULL | каноническое объявленное имя |
| `module_id`, `file_id`, `line` | | атрибуция |

Без этой таблицы `\Bitrix\Sale\OrderTable` не находится, хотя используется повсеместно (R-006). Поиск символа MUST разрешать псевдоним и возвращать обе формы имени.

### `method` и `parameter`

`method`: `symbol_id`, `name`, `name_lower`, `visibility`, `is_static`, `is_abstract`, `is_final`, `return_type`, `doc_comment`, `line`, `signature`.

`parameter`: `method_id`, `position`, `name`, `type`, `default_repr`, `by_ref`, `variadic`, `nullable`.

`signature` — заранее отрендеренная строка сигнатуры. Денормализация оправдана Принципом IV: это ровно то, что уходит агенту, и собирать её из параметров на каждый запрос значит платить за то, что не меняется между запросами.

`default_repr` хранит исходное текстовое представление значения по умолчанию, а не вычисленное значение — вычисление потребовало бы выполнения кода (Принцип VI).

---

## ORM

### `orm_entity`

| Колонка | Тип | Назначение |
|---|---|---|
| `symbol_id` | INTEGER FK | класс-наследник менеджера данных |
| `table_name` | TEXT | имя таблицы |
| `table_name_source` | TEXT NOT NULL | `literal` \| `unresolved` |
| `extraction_style` | TEXT NOT NULL | `array` \| `fluent` \| `mixed` \| `inherited` |
| `is_complete` | INTEGER NOT NULL | извлечена ли карта полей целиком |
| `incomplete_reason` | TEXT | что именно не удалось |

### `orm_field`

| Колонка | Тип | Назначение |
|---|---|---|
| `orm_entity_id` | INTEGER FK | |
| `name` | TEXT NOT NULL | имя поля |
| `data_type` | TEXT | `integer`, `string`, `datetime`, `enum` |
| `is_primary`, `is_required`, `is_autocomplete` | INTEGER | |
| `default_repr` | TEXT | текстовое представление |
| `is_default_resolved` | INTEGER NOT NULL | 0, если задано замыканием |
| `reference_target_fqn` | TEXT | целевая сущность для связи |
| `join_condition` | TEXT | условие соединения |
| `values_repr` | TEXT | допустимые значения перечисления |

Поддерживаются обе формы объявления (R-003). Замыкания в `default_value` и `validation` дают `is_default_resolved = 0`, а сущность — `is_complete = 0` с указанием причины. Молчаливое «поле без значения по умолчанию» запрещено Принципом III.

---

## События, компоненты, REST

### `event`

`module_id`, `name`, `name_lower`, `kind` (`send` — модуль вызывает событие, `register` — модуль подписывается), `call_style` (`GetModuleEvents` \| `ExecuteModuleEventEx` \| `EventManager` \| `new Event`), `file_id`, `line`, `params_repr`.

Разделение `kind` отвечает на два разных вопроса агента: «какие события бросает этот модуль» и «кто уже подписан».

### `component` и `component_param`

`component`: `module_id`, `namespace`, `name`, `full_name` (`bitrix:main.feedback`), `title`, `description`, `rel_path`.

`component_param`: `component_id`, `code`, `type`, `name_repr`, `default_repr`, `is_default_resolved`, `parent_group`, `multiple`, `values_repr`.

`is_default_resolved = 0` для значений, вычисляемых в рантайме через `GetMessage()` или `COption::GetOptionString()` — прямое следствие запрета на выполнение кода (R-004).

### `rest_method`

`module_id`, `name`, `handler_repr`, `file_id`, `line`.

### `module_dependency`

`module_id`, `file_id`, `depends_on_code`, `occurrences`. Ключ `(module_id, file_id, depends_on_code)` сохраняет источник каждой связи; удаление файла каскадом удаляет его зависимости. В MCP ответе записи группируются по `depends_on_code` с суммой `occurrences`.

---

## Документация

### `doc_page`

`url` (UNIQUE), `section`, `title`, `content`, `fetched_at`, `etag`,
`content_sha256`, `version_mentions`, `source_type`, `source_ref`, `source_revision`.

`version_mentions` хранит извлечённые из прозы упоминания вида «начиная с версии X.Y» как **справочный признак**, а не как версионную привязку — документация не версионирована, и выдавать её утверждения за факт о сборке запрещено Принципом I.

---

## Полнотекстовый поиск

Три таблицы FTS5 на внешнем содержимом (`content=`), чтобы не дублировать данные:

| Таблица | Над чем | Токенизатор | Зачем |
|---|---|---|---|
| `fts_doc` | `doc_page(title, content)` | `unicode61 remove_diacritics 2` | русскоязычный поиск, FR-032 |
| `fts_symbol_doc` | `symbol(doc_comment)`, `method(doc_comment, signature)` | `unicode61` | поиск по описанию задачи |
| `fts_identifier` | `symbol(fqn, short_name)` | `trigram` | поиск по фрагменту имени |

**Точный лукап идёт мимо FTS.** Для имён классов и методов используются обычные B-tree индексы: для идентификаторов важно точное и префиксное совпадение, а не морфология. FTS здесь дал бы худшее ранжирование при большей стоимости.

### Индексы

```
idx_symbol_short_lower   symbol(short_name_lower)
idx_symbol_fqn_lower     symbol(fqn_lower)
idx_symbol_module        symbol(module_id)
idx_alias_lower          symbol_alias(alias_fqn_lower)
idx_method_symbol        method(symbol_id)
idx_method_name_lower    method(name_lower)
idx_event_name_lower     event(name_lower)
idx_event_module         event(module_id, kind)
idx_ormfield_entity      orm_field(orm_entity_id)
idx_file_relpath         file(rel_path)
idx_component_fullname   component(full_name)
```

`idx_symbol_short_lower` — самый горячий путь: агент почти всегда знает короткое имя (`OrderTable`), а не полное.

---

## Правила целостности

- Удаление `file` каскадно удаляет `symbol`, `event`, `component`, `rest_method` и всё, что через них подвешено. Это делает инкрементальное удаление одной операцией (R-010).
- `symbol.fqn` не уникален глобально: одно и то же имя может встречаться в разных модулях, и выдача обязана возвращать все варианты, а не первый (краевой случай из спецификации).
- Запись выполняется в одной транзакции на файл, что сохраняет согласованность при прерывании прогона.
- Сервер открывает файл в режиме только для чтения. Попытка записи со стороны сервера — дефект (Принцип VI).

## Соответствие сущностям спецификации

| Сущность спецификации | Таблицы |
|---|---|
| Снимок сборки | `snapshot` |
| Модуль | `module`, `module_dependency` |
| Символ | `symbol`, `symbol_interface`, `symbol_alias` |
| Метод | `method`, `parameter` |
| ORM-сущность | `orm_entity`, `orm_field` |
| Событие | `event` |
| Компонент | `component`, `component_param` |
| REST-метод | `rest_method` |
| Страница документации | `doc_page` |
