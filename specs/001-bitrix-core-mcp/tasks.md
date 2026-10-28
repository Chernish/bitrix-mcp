---

description: "Task list for feature implementation"
---

# Tasks: MCP-сервер по ядру 1С-Битрикс с версионной привязкой

**Input**: Design documents from `/specs/001-bitrix-core-mcp/`

**Prerequisites**: [plan.md](./plan.md), [spec.md](./spec.md), [research.md](./research.md), [data-model.md](./data-model.md), [contracts/](./contracts/)

**Tests**: тестовые задачи включены. Это не выбор по умолчанию, а требование конституции — раздел «Качество данных и проверка» объявляет тесты обязательными для извлечения версий, резолва пространств имён и разбора карт ORM, а [contracts/mcp-tools.md](./contracts/mcp-tools.md) фиксирует 8 контрактных инвариантов.

**Organization**: задачи сгруппированы по пользовательским историям. После Phase 3 проект представляет собой работающий MVP, остальные фазы — независимые надстройки.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: можно выполнять параллельно (разные файлы, нет незакрытых зависимостей)
- **[Story]**: к какой истории относится задача (US1…US6)
- Пути к файлам указаны явно

## Path Conventions

Два компонента из [plan.md](./plan.md): `indexer/` на PHP (единственный писатель индекса) и `server/` на TypeScript (только читатель). Граница — файл индекса и `snapshot.schema_version`.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: инициализация репозитория и обоих компонентов

- [X] T001 Создать структуру каталогов `indexer/` и `server/` согласно разделу Project Structure в plan.md
- [X] T002 Инициализировать PHP-проект в `indexer/composer.json` с зависимостями `nikic/php-parser:^5.8` и `phpunit/phpunit`, автозагрузка PSR-4 на `indexer/src/`
- [X] T003 [P] Инициализировать TypeScript-проект в `server/package.json` с `@modelcontextprotocol/sdk`, `better-sqlite3`, настроить `server/tsconfig.json`
- [X] T004 [P] Настроить статический анализ и форматирование: `indexer/phpstan.neon`, `indexer/phpcs.xml`, конфигурация линтера в `server/`
- [X] T005 Создать `Makefile` в корне репозитория, где **каждая** цель, запускающая PHP, передаёт `-d short_open_tag=1`; цели `build`, `update`, `docs`, `status`, `verify`, `test`
- [X] T006 [P] Добавить `.gitignore` для `index.sqlite`, `vendor/`, `node_modules/`, `.docs-cache/`

**Примечание к T005**: жёсткая привязка флага к точкам запуска — вторая линия обороны после preflight из T007. Полагаться на `php.ini` машины нельзя: в целевом окружении настройка выключена по умолчанию (R-001).

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: то, без чего любой построенный индекс будет недостоверен

**⚠️ CRITICAL**: ни одна история не может начаться до завершения этой фазы. Порядок здесь не организационный, а содержательный: preflight и карта версий должны существовать раньше первого визитора, иначе появится соблазн «дописать версию потом», а индекс без версий нарушает NON-NEGOTIABLE Принцип II.

### Защита от тихой потери данных (R-001)

- [X] T007 Реализовать проверку `short_open_tag` в `indexer/src/Preflight/ShortOpenTagGuard.php`: чтение `ini_get('short_open_tag')`, аварийный выход с кодом 2 и текстом из contracts/indexer-cli.md **до** открытия БД и обхода файлов
- [X] T008 [P] Создать фикстуру `indexer/tests/fixtures/short-tag-class.php`, открывающуюся тегом `<?` и содержащую класс с методом
- [X] T009 [P] Написать модульный тест `indexer/tests/Unit/ShortOpenTagGuardTest.php`: при выключенной настройке страж завершает работу кодом 2 и не создаёт файл индекса
- [X] T010 Написать тест `indexer/tests/Unit/ShortTagParsingTest.php`: разбор фикстуры из T008 при `short_open_tag=1` возвращает узел класса, а не `InlineHTML`

**Обоснование раннего размещения T008–T010**: дефект из R-001 не проявляется как ошибка. При выключенной настройке парсер возвращает синтаксически корректный результат с нулём объявлений, и без этого теста регрессия пройдёт незамеченной до момента, когда агент получит «не найдено» на существующий класс.

### Конфигурация и версионная атрибуция

- [X] T011 Реализовать разрешение конфигурации в `indexer/src/Core/Config.php`: приоритет CLI → переменные окружения → файл → умолчания; `BITRIX_CORE_ROOT` без значения по умолчанию, отказ кодом 1 при его отсутствии; поддержка `BITRIX_EXTRA_ROOTS` со **списком** корней индексации, где ядро — первый элемент, а не единственный (FR-014)
- [X] T012 Реализовать чтение версий в `indexer/src/Core/ModuleVersionReader.php`: `$arModuleVersion` из `modules/<code>/install/version.php`, константа `SM_VERSION` для `main`, заполнение `version_source` значениями `module_file` / `sm_version` / `unknown`
- [X] T013 Написать тест `indexer/tests/Unit/ModuleVersionReaderTest.php` на трёх случаях: обычный модуль (`crm` → `24.1300.0`), `main` через `SM_VERSION` (`25.100.300`), модуль без файла версии → `unknown` без подстановки версии сборки

**T013 проверяет самый опасный сценарий**: подстановка версии сборки вместо неизвестной версии модуля превращает честное незнание в уверенную ложь и прямо запрещена Принципом II.

### Схема хранилища

- [X] T014 Создать схему в `indexer/src/Storage/Schema.php`: таблицы `snapshot`, `module`, `file` по data-model.md, включая колонки `short_open_tag_enabled`, `version_source`, `seen_in_pass`, `schema_version`
- [X] T015 Реализовать запись снимка в `indexer/src/Storage/SnapshotWriter.php` с фиксацией `sm_version`, карты версий, счётчиков и состояния `short_open_tag` на момент индексации
- [X] T016 Реализовать обход дерева в `indexer/src/Core/FileWalker.php`: сбор `(size, mtime, sha256)`, привязка каждого файла к `module_id`, установка `seen_in_pass`; обход принимает **список** корней из T011 и хранит `rel_path` относительно своего корня, чтобы добавление корня не требовало правки модели владения (FR-014)
- [X] T017 Реализовать накопление ошибок разбора в `indexer/src/Core/ParseErrorCollector.php`: ошибка одного файла не прерывает проход, попадает в отчёт и в `snapshot.parse_error_count`
- [X] T018 [P] Реализовать проверку совместимости схемы в `indexer/src/Storage/SchemaVersionGuard.php` с кодом возврата 5

**Порядок T014 перед всеми визиторами не случаен**: связь `module_id` создаётся до появления первой извлекаемой сущности, что делает запись факта без версии структурно невозможной.

**T011 и T016 вместе закрывают FR-014.** Список корней закладывается сейчас, хотя в этой итерации используется один: модель владения сущностями меняется тяжело, и переход от «один корень» к «список» после того, как её начали использовать все визиторы, — это переработка, а не настройка. Ровно этого требование и просило избежать.

---

## Phase 3: User Story 1 — Точная сигнатура API с версионной привязкой (P1) 🎯 MVP

**Goal**: агент запрашивает класс или метод и получает точную сигнатуру с указанием модуля, его версии, файла и строки.

**Independent Test**: контрольная выборка существующих символов находится с верной версией, заведомо несуществующие — не находятся. Сценарии 3–6 и 14 из [quickstart.md](./quickstart.md).

### Индексатор: извлечение символов

- [X] T019 [US1] Расширить схему в `indexer/src/Storage/Schema.php` таблицами `symbol`, `method`, `parameter`, `symbol_interface`, `symbol_alias` по data-model.md
- [X] T020 [US1] Реализовать `indexer/src/Extract/SymbolVisitor.php`: классы, интерфейсы, трейты и перечисления с применением `NameResolver`, заполнение `fqn`, `short_name`, их вариантов в нижнем регистре, `kind`, `parent_fqn`, `line`, а также **запись списка реализуемых интерфейсов в `symbol_interface`** с резолвом каждого имени в полное
- [X] T021 [US1] Реализовать извлечение методов и параметров в `indexer/src/Extract/MethodExtractor.php`: видимость, модификаторы, типы, `default_repr` как исходное текстовое представление, тип возврата
- [X] T022 [US1] Реализовать рендеринг сигнатуры в `indexer/src/Extract/SignatureRenderer.php` с сохранением результата в `method.signature`
- [X] T023 [P] [US1] Реализовать `indexer/src/Extract/AliasVisitor.php` для `class_alias`, запись в `symbol_alias` с обеими формами имени
- [X] T024 [P] [US1] Реализовать `indexer/src/Extract/DeprecationDetector.php`: **раздельно** `is_deprecated` с текстом из `@deprecated` и эвристический `api_style` (`legacy` / `d7` / `unknown`)
- [X] T025 [US1] Реализовать запись символов в `indexer/src/Storage/SymbolWriter.php` с транзакцией на файл
- [X] T026 [US1] Создать B-tree индексы `idx_symbol_short_lower`, `idx_symbol_fqn_lower`, `idx_symbol_module`, `idx_alias_lower`, `idx_method_symbol`, `idx_method_name_lower` в `indexer/src/Storage/Schema.php`
- [X] T027 [US1] Реализовать команду `build` в `indexer/bin/index.php` в порядке: preflight → `SM_VERSION` → карта версий → обход → извлечение → индексы → снимок
- [X] T028 [US1] Реализовать команду `status` в `indexer/bin/index.php` с флагами `--deep` и `--json`

**T024 разделяет два поля намеренно**: `is_deprecated` — прочитанный факт, `api_style` — вывод по форме имени. Это единственное задокументированное отклонение от Принципа III (см. Complexity Tracking в plan.md), и оно допустимо ровно потому, что догадка остаётся отличимой от факта.

### Индексатор: тесты

- [X] T029 [P] [US1] Тест `indexer/tests/Unit/NameResolutionTest.php`: разрешение `use`-импортов, псевдонимов импортов и относительных имён в полные
- [X] T030 [P] [US1] Тест `indexer/tests/Integration/ShortTagRegressionTest.php`: полный прогон индексации на каталоге с фикстурой из T008 находит класс из файла с коротким тегом
- [X] T031 [P] [US1] Тест `indexer/tests/Integration/AliasResolutionTest.php`: поиск по `\Bitrix\Sale\OrderTable` находит символ и возвращает `canonical_fqn` равным `\Bitrix\Sale\Internals\OrderTable`
- [X] T032 [P] [US1] Тест `indexer/tests/Integration/DeterminismTest.php`: два прогона `build` на неизменном дереве дают идентичное содержимое индекса (Принцип V)

### Сервер: доступ и конверт

- [X] T033 [US1] Реализовать доступ к БД в `server/src/db/index.ts` в режиме только для чтения с проверкой `snapshot.schema_version`
- [X] T034 [US1] Реализовать конверт в `server/src/envelope.ts`: сборка `provenance`, различение `module_version` и `version_unknown`, поле `stale`
- [X] T035 [US1] Реализовать дешёвый детект рассинхрона в `server/src/staleness.ts`: сверка `SM_VERSION` из файла ядра со значением в снимке на каждом запросе
- [X] T036 [US1] Реализовать отказ при `snapshot.short_open_tag_enabled = 0` в `server/src/db/index.ts`: индекс считается непригодным, предупреждение выдаётся при каждом запросе
- [X] T037 [US1] Реализовать точку входа stdio в `server/src/index.ts` с направлением всей диагностики в stderr и **единым реестром инструментов** в `server/src/tools/registry.ts`, из которого берут список и регистрация в MCP, и контрактные тесты T042–T049

### Сервер: инструменты MVP

- [X] T038 [P] [US1] Реализовать `bitrix_find_symbol` в `server/src/tools/find-symbol.ts`: точное совпадение короткого имени → полного → префиксное, разрешение псевдонимов, явное усечение
- [X] T039 [P] [US1] Реализовать `bitrix_get_class` в `server/src/tools/get-class.ts` с умолчанием `limit: 40`, фильтром видимости и пагинацией
- [X] T040 [P] [US1] Реализовать `bitrix_check_method` в `server/src/tools/check-method.ts` с раздельными полями `exists` и `class_exists`
- [X] T041 [US1] Реализовать `bitrix_index_status` в `server/src/tools/index-status.ts`

### Сервер: контрактные тесты

Восемь инвариантов из [contracts/mcp-tools.md](./contracts/mcp-tools.md) вынесены в отдельные задачи, а не в подпункты реализации: они проверяют свойства всей поверхности инструментов, а не поведение одного из них.

**Тесты T042–T049 MUST быть параметризованы по реестру инструментов**, а не написаны против конкретного списка. На момент Phase 3 инструментов четыре, к Phase 9 их десять. Тест, перечисляющий инструменты вручную, останется зелёным и при этом перестанет покрывать шесть добавленных позже — то есть создаст видимость защиты NON-NEGOTIABLE Принципа II там, где её нет. Реестр инструментов сервера обязан быть единственным источником списка и для регистрации в MCP, и для этих тестов.

- [X] T042 [P] [US1] Контрактный тест №1 в `server/tests/contract/provenance.test.ts`: каждый инструмент на успешном ответе возвращает `module_version` либо `version_unknown = true`
- [X] T043 [P] [US1] Контрактный тест №2 в `server/tests/contract/context-economy.test.ts`: ни один инструмент не возвращает содержимое файла целиком; типичный ответ укладывается в 2000 символов (SC-007)
- [X] T044 [P] [US1] Контрактный тест №3 в `server/tests/contract/no-guessing.test.ts`: несуществующее имя даёт `found: false` без похожих имён в основном результате
- [X] T045 [P] [US1] Контрактный тест №4 в `server/tests/contract/alias.test.ts`: запрос по псевдониму находит класс и отдаёт каноническое имя
- [X] T046 [P] [US1] Контрактный тест №5 в `server/tests/contract/short-tag.test.ts`: класс из файла с коротким тегом находится через инструмент
- [X] T047 [P] [US1] Контрактный тест №6 в `server/tests/contract/staleness.test.ts`: при подменённом `SM_VERSION` **все** инструменты выставляют `stale = true`
- [X] T048 [P] [US1] Контрактный тест №7 в `server/tests/contract/truncation.test.ts`: усечённая выдача всегда сопровождается `truncated` и `total`
- [X] T049 [P] [US1] Контрактный тест №8 в `server/tests/contract/read-only.test.ts`: попытка записи в индекс со стороны сервера отвергается

### Приёмка истории

- [X] T050 [US1] Создать контрольную выборку `indexer/tests/fixtures/known-symbols.json`: не менее 50 существующих символов с ожидаемыми модулем и версией и не менее 30 заведомо несуществующих имён
- [X] T051 [US1] Реализовать команду `verify` в `indexer/bin/index.php` со сверкой по выборке из T050 и ненулевым кодом возврата при расхождении
- [X] T052 [US1] Прогнать сценарии 1–6 и 14 из quickstart.md на реальном ядре, зафиксировать фактические время индексации и долю ошибок разбора

**Checkpoint**: после T052 существует работающий MVP. Агент получает точные сигнатуры с версиями, а инварианты достоверности защищены автоматическими тестами. Остановка здесь допустима.

---

## Phase 4: User Story 2 — ORM-схема сущности (P2)

**Goal**: агент получает состав ORM-сущности: таблицу, поля, типы, обязательность, связи.

**Independent Test**: сценарий 7 из quickstart.md на обеих формах объявления.

- [X] T053 [US2] Расширить схему таблицами `orm_entity` и `orm_field` в `indexer/src/Storage/Schema.php`, включая `is_complete`, `incomplete_reason`, `is_default_resolved`, `table_name_source`
- [X] T054 [US2] Реализовать определение ORM-сущностей в `indexer/src/Extract/OrmEntityDetector.php`: наследники менеджера данных, извлечение имени таблицы из `getTableName()`
- [X] T055 [US2] Реализовать разбор формы литерального массива в `indexer/src/Extract/Orm/ArrayMapParser.php`: ключи полей, `data_type`, `primary`, `required`, `autocomplete`
- [X] T056 [US2] Реализовать разбор fluent-формы в `indexer/src/Extract/Orm/FluentMapParser.php`: имя поля из первого аргумента конструктора, тип из имени класса поля, конфигурация из цепочки `configureXxx()`
- [X] T057 [US2] Реализовать извлечение связей в `indexer/src/Extract/Orm/ReferenceParser.php`: целевая сущность из `::class` и условие соединения
- [X] T058 [US2] Реализовать пометку неполноты в `indexer/src/Extract/Orm/CompletenessMarker.php`: замыкания в `default_value` и `validation` дают `is_default_resolved = 0` и `is_complete = 0` с указанием причины
- [X] T059 [P] [US2] Тест `indexer/tests/Unit/ArrayMapParserTest.php` на форме литерального массива
- [X] T060 [P] [US2] Тест `indexer/tests/Unit/FluentMapParserTest.php` на fluent-форме, включая связь и перечисление
- [X] T061 [P] [US2] Тест `indexer/tests/Unit/OrmCompletenessTest.php`: сущность с замыканием в значении по умолчанию помечается неполной, а не отдаётся как полная
- [X] T062 [US2] Реализовать `bitrix_get_orm_entity` в `server/src/tools/orm-entity.ts` с обязательной передачей `is_complete` и `incomplete_reason`

**T055 и T056 — две отдельные задачи, а не одна**: замер показал 1463 файла с `getMap()`, из них 504 в fluent-стиле (R-003). Реализация одной формы оставит вторую невидимой для агента, причём без каких-либо признаков сбоя.

**Checkpoint**: US1 и US2 работают независимо.

---

## Phase 5: User Story 3 — События модуля (P2)

**Goal**: агент получает имена событий модуля, точки вызова и параметры.

**Independent Test**: сценарий 8 из quickstart.md.

- [X] T063 [US3] Расширить схему таблицей `event` в `indexer/src/Storage/Schema.php` с колонками `kind`, `call_style`, индексами `idx_event_name_lower` и `idx_event_module`
- [X] T064 [US3] Реализовать извлечение событий старого API в `indexer/src/Extract/Event/LegacyEventVisitor.php`: `GetModuleEvents` и `ExecuteModuleEventEx` со строковыми литералами модуля и имени
- [X] T065 [P] [US3] Реализовать извлечение событий D7 в `indexer/src/Extract/Event/D7EventVisitor.php`: `new Event('module', 'Name')` и отправка через менеджер событий
- [X] T066 [US3] Реализовать различение `kind` (`send` против `register`) в `indexer/src/Extract/Event/EventKindClassifier.php`
- [X] T067 [P] [US3] Тест `indexer/tests/Unit/EventExtractionTest.php` на обоих поколениях API и обоих значениях `kind`
- [X] T068 [US3] Реализовать `bitrix_list_events` в `server/src/tools/list-events.ts`

**Checkpoint**: US1–US3 работают независимо.

---

## Phase 6: User Story 4 — Инкрементальная переиндексация (P2)

**Goal**: одна команда приводит индекс в соответствие с обновлённым ядром.

**Independent Test**: сценарии 11 и 12 из quickstart.md.

- [X] T069 [US4] Реализовать команду `update` в `indexer/bin/index.php`: быстрый фильтр по `(size, mtime)`, сверка SHA-256 при расхождении, перестроение только изменившегося
- [X] T070 [US4] Реализовать удаление исчезнувших файлов в `indexer/src/Core/OrphanCleaner.php` через `seen_in_pass` с каскадом по `file_id`
- [X] T071 [US4] Обеспечить полное перечитывание карты версий модулей при каждом `update` в `indexer/src/Core/ModuleVersionReader.php` независимо от инкрементальности
- [X] T072 [US4] Реализовать три уровня обнаружения рассинхрона в `server/src/staleness.ts`: сверка версии сборки, перечитывание карты версий, полная сверка при переиндексации
- [X] T073 [P] [US4] Тест `indexer/tests/Integration/IncrementalUpdateTest.php`: изменение файла отражается, удаление файла убирает записи, неизменное дерево не перестраивается
- [X] T074 [P] [US4] Тест `server/tests/unit/staleness.test.ts` на всех трёх уровнях обнаружения

**T071 сформулирована отдельно намеренно**: карта версий дёшева (99 файлов) и критична для Принципа II, поэтому оптимизировать её чтение через инкрементальность нельзя.

**Checkpoint**: индекс поддерживается в актуальном состоянии, устаревание видно в каждом ответе.

---

## Phase 7: User Story 5 — Параметры компонентов и REST-методы (P3)

**Goal**: агент получает параметры компонента и перечень REST-методов модуля.

**Independent Test**: сценарий 9 из quickstart.md.

- [X] T075 [US5] Расширить схему таблицами `component`, `component_param`, `rest_method`, `module_dependency` в `indexer/src/Storage/Schema.php`
- [X] T076 [US5] Реализовать разбор `.parameters.php` в `indexer/src/Extract/Component/ParametersParser.php` **статическим анализом без выполнения файла**: литеральные `TYPE`, `PARENT`, `DEFAULT`, `VALUES`
- [X] T077 [US5] Реализовать пометку вычисляемых значений в `indexer/src/Extract/Component/ParametersParser.php`: вызовы вида `GetMessage()` и `COption::GetOptionString()` дают `is_default_resolved = 0`
- [X] T078 [P] [US5] Реализовать разбор `.description.php` в `indexer/src/Extract/Component/DescriptionParser.php`
- [X] T079 [P] [US5] Реализовать `indexer/src/Extract/RestVisitor.php` для методов, регистрируемых через `OnRestServiceBuildDescription`
- [X] T080 [P] [US5] Реализовать извлечение зависимостей модулей в `indexer/src/Extract/ModuleDependencyExtractor.php` (FR-011, данные складываются, инструмент в этой итерации не создаётся)
- [X] T081 [P] [US5] Тест `indexer/tests/Unit/ComponentParametersTest.php`: литеральные значения извлечены, вычисляемые помечены неразрешёнными, файл не выполнялся
- [X] T082 [US5] Реализовать `bitrix_get_component` в `server/src/tools/get-component.ts` с полем `unresolved_count`
- [X] T083 [P] [US5] Реализовать `bitrix_list_rest_methods` в `server/src/tools/rest-methods.ts`

**T077 фиксирует цену Принципа VI**: в реальных файлах ядра `DEFAULT` вычисляется через настройки модуля и запросы к БД (R-004). Ненулевой `unresolved_count` — ожидаемое состояние, а не дефект, и агент обязан видеть разницу между «значения нет» и «значение вычисляется в рантайме».

**Checkpoint**: покрыты все сущности ядра.

---

## Phase 8: User Story 6 — Поиск по документации (P3)

**Goal**: агент находит концептуальные объяснения со ссылками на источник.

**Independent Test**: сценарий 10 из quickstart.md, включая запрос на кириллице.

- [X] T084 [US6] Расширить схему таблицей `doc_page` и таблицей `fts_doc` с токенизатором `unicode61 remove_diacritics 2` в `indexer/src/Storage/Schema.php`
- [X] T085 [US6] Реализовать чтение `robots.txt` и `sitemap.xml` в `indexer/src/Docs/SitemapReader.php` с уважением запрета на `/_search/`
- [X] T086 [US6] Реализовать HTTP-клиент в `indexer/src/Docs/HttpFetcher.php` **прямыми запросами**, с паузой между обращениями и настраиваемым таймаутом
- [X] T087 [US6] Реализовать кэш в `indexer/src/Docs/PageCache.php` по `ETag` и SHA-256 содержимого, чтобы повторный запуск не выполнял лишних загрузок
- [X] T088 [US6] Реализовать извлечение содержимого в `indexer/src/Docs/ContentExtractor.php`: раздел, заголовок, текст, а также упоминания версий в `version_mentions` как справочный признак
- [X] T089 [US6] Реализовать команду `docs` в `indexer/bin/index.php` с флагом `--force` и кодом возврата 4 при сетевой ошибке, не повреждающей индекс кода
- [X] T090 [US6] Реализовать `bitrix_search_docs` в `server/src/tools/search-docs.ts` с поддержкой кириллических запросов
- [X] T091 [US6] Реализовать обнаружение расхождения в `server/src/tools/search-docs.ts`: если документация утверждает существование отсутствующего в индексе символа, ответ содержит `code_conflict` с приоритетом кода
- [X] T092 [P] [US6] Тест `server/tests/unit/cyrillic-search.test.ts` на поиске по русскоязычным запросам
- [X] T093 [P] [US6] Тест `indexer/tests/Integration/DocsCacheTest.php`: повторный запуск не выполняет повторных загрузок неизменившихся страниц

**T086 требует собственного HTTP-клиента**: штатный механизм загрузки веб-страниц агента к целевому домену заблокирован корпоративной сетевой политикой, тогда как прямые запросы проходят (R-011).

---

## Phase 9: Polish & Cross-Cutting Concerns

- [X] T094 [P] Реализовать таблицы `fts_symbol_doc` и `fts_identifier` в `indexer/src/Storage/Schema.php` на внешнем содержимом
- [X] T095 Реализовать `bitrix_search_code` в `server/src/tools/search-code.ts` с выдачей фрагментов PHPDoc вместо содержимого файлов
- [X] T096 [P] Реализовать порог доли ошибок разбора с кодом возврата 3 в `indexer/bin/index.php` (SC-009, порог 1%)
- [X] T097 [P] Замерить и зафиксировать соответствие SC-004 (лукап менее 1 с) и SC-005 (повторная индексация не более 10% времени полной) в `specs/001-bitrix-core-mcp/quickstart.md`
- [X] T098 [P] Проверить переносимость индекса по сценарию 14 quickstart.md: копирование файла на другой путь не требует переиндексации
- [X] T099 [P] Написать `README.md` в корне: установка, обязательный флаг `short_open_tag`, подключение сервера к агенту
- [X] T100 Прогнать все 14 сценариев quickstart.md на реальном ядре и зафиксировать результаты

---

## Dependencies

### Порядок фаз

```
Phase 1 (Setup)
    ↓
Phase 2 (Foundational) ← БЛОКИРУЕТ ВСЁ
    ↓
Phase 3 (US1, P1) ← MVP, точка возможной остановки
    ↓
    ├── Phase 4 (US2, P2) ─┐
    ├── Phase 5 (US3, P2) ─┤ независимы друг от друга
    ├── Phase 6 (US4, P2) ─┤
    ├── Phase 7 (US5, P3) ─┤
    └── Phase 8 (US6, P3) ─┘
                ↓
        Phase 9 (Polish)
```

### Внутрифазовые зависимости

- **Phase 2**: T007 → T009, T010. T012 → T013. T014 → T015, T016. Ветка T007–T010 независима от ветки T011–T013 и может идти параллельно.
- **Phase 3**: T019 → T020 → T021 → T022 → T025 → T026 → T027. T033 → T034 → T035 → T037. Инструменты T038–T040 параллельны между собой, но требуют T034. Контрактные тесты T042–T049 требуют T038–T041 и параллельны между собой.
- **Phase 4**: T053 → T054 → {T055, T056} → T057 → T058 → T062.
- **Phase 8**: T084 → T085 → T086 → T087 → T088 → T089 → T090 → T091.

### Зависимости между историями

US2–US6 зависят только от Phase 2 и от схемы символов из T019, а друг от друга — нет. После завершения Phase 3 их можно реализовывать в любом порядке или параллельно.

---

## Parallel Execution Examples

**Phase 2, две независимые ветки:**

```
Ветка A (защита от потери данных): T007 → T009, T010
Ветка B (версии и конфигурация):   T011 → T012 → T013
```

**Phase 3, инструменты сервера после готовности конверта T034:**

```
T038 (find_symbol) ┐
T039 (get_class)   ├─ параллельно, разные файлы
T040 (check_method)┘
```

**Phase 3, все восемь контрактных тестов параллельны:**

```
T042, T043, T044, T045, T046, T047, T048, T049
```

**Phase 4, две формы разбора карт полей:**

```
T055 (литеральный массив) ─┐ параллельно, разные файлы
T056 (fluent-стиль)       ─┘
```

**После Phase 3 — пять историй параллельно** при наличии исполнителей: Phase 4, 5, 6, 7, 8.

---

## Implementation Strategy

### MVP

**Phase 1 + Phase 2 + Phase 3** (T001–T052, 52 задачи). Результат: индекс символов ядра и три инструмента точного лукапа с версионной привязкой. Это закрывает главную причину ошибок агента — уверенные утверждения о несуществующем API.

Остановка после T052 даёт работоспособный продукт. Истории P2 и P3 расширяют покрытие, но не требуются для получения пользы.

### Порядок дальнейших приращений

Рекомендуемый порядок после MVP — по частоте обращений агента: **US2 (ORM)** → **US4 (инкрементальность)** → **US3 (события)** → **US5 (компоненты и REST)** → **US6 (документация)**.

US4 стоит выше US3 не по приоритету спецификации, а по практической причине: как только индексом начинают пользоваться ежедневно, устаревание становится более вероятным источником неверных ответов, чем отсутствие событий в выдаче.

### Что нельзя откладывать

Задачи T007–T010 и T042–T049 выглядят инфраструктурными, но защищают инварианты достоверности. Их перенос в конец обесценивает проект: индекс, который молча теряет 41% ядра или отдаёт факты без версий, хуже отсутствия индекса, потому что выглядит достоверным.

---

## Summary

| Фаза | История | Задач | Приоритет |
|---|---|---|---|
| 1. Setup | — | 6 | — |
| 2. Foundational | — | 12 | блокирующая |
| 3. Символы и лукап | US1 | 34 | P1 (MVP) |
| 4. ORM | US2 | 10 | P2 |
| 5. События | US3 | 6 | P2 |
| 6. Инкрементальность | US4 | 6 | P2 |
| 7. Компоненты и REST | US5 | 9 | P3 |
| 8. Документация | US6 | 10 | P3 |
| 9. Polish | — | 7 | — |
| **Итого** | | **100** | |
