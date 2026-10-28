<?php

declare(strict_types=1);

namespace BitrixMcp\Indexer\Storage;

use PDO;

/**
 * Схема индекса.
 *
 * Два свойства схемы реализуют принципы конституции структурно, а не дисциплиной кода:
 *
 * 1. Каждая содержательная сущность связана с `module` через NOT NULL внешний ключ,
 *    а `module` хранит версию. Записать факт без версионной атрибуции невозможно —
 *    это Принцип II, выраженный в DDL.
 *
 * 2. Каждая извлечённая запись связана с `file`, а каскад по `file_id` делает
 *    инкрементальное удаление одной операцией (R-010).
 *
 * Индексы создаются ОТДЕЛЬНО от таблиц и уже после массовой вставки: построение
 * B-tree на лету замедляет загрузку в разы.
 */
final class Schema
{
    /**
     * Версия схемы. Повышается при любом несовместимом изменении.
     * Сервер отказывается читать индекс с чужой версией (код возврата 5)
     * вместо того, чтобы истолковать данные неверно.
     */
    public const VERSION = 9;

    public static function create(PDO $pdo): void
    {
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA foreign_keys = ON');

        foreach (self::tableStatements() as $sql) {
            $pdo->exec($sql);
        }
    }

    /**
     * Создание индексов. Вызывается после массовой вставки.
     */
    public static function createIndexes(PDO $pdo): void
    {
        foreach (self::indexStatements() as $sql) {
            $pdo->exec($sql);
        }
    }

    /**
     * Перестроение полнотекстового индекса документации.
     *
     * FTS5 на внешнем содержимом не наполняется сам: он читает данные
     * из doc_page по команде rebuild. Вызывается после сбора страниц.
     */
    public static function rebuildDocsIndex(PDO $pdo): void
    {
        $pdo->exec("INSERT INTO fts_doc(fts_doc) VALUES('rebuild')");
    }

    /**
     * Наполнение полнотекстового индекса по коду.
     *
     * Выполняется одним INSERT..SELECT после массовой вставки: построчная
     * вставка в FTS во время обхода замедлила бы индексацию в разы.
     *
     * В индекс попадают только символы и методы, у которых есть PHPDoc:
     * поиск по описанию задачи имеет смысл лишь там, где описание есть,
     * а 163 тысячи сигнатур без комментариев только зашумили бы выдачу.
     */
    public static function buildCodeIndex(PDO $pdo): void
    {
        $pdo->exec('DELETE FROM fts_code');

        $pdo->exec(
            "INSERT INTO fts_code (fqn, member, signature, doc, module, rel_path, line)
             SELECT s.fqn, me.name, me.signature, me.doc_comment, m.code, f.rel_path, me.line
             FROM method me
             JOIN symbol s ON s.id = me.symbol_id
             JOIN module m ON m.id = s.module_id
             JOIN file   f ON f.id = s.file_id
             WHERE me.doc_comment IS NOT NULL AND LENGTH(me.doc_comment) > 40"
        );

        $pdo->exec(
            "INSERT INTO fts_code (fqn, member, signature, doc, module, rel_path, line)
             SELECT s.fqn, '', s.kind || ' ' || s.short_name, s.doc_comment, m.code, f.rel_path, s.line
             FROM symbol s
             JOIN module m ON m.id = s.module_id
             JOIN file   f ON f.id = s.file_id
             WHERE s.doc_comment IS NOT NULL AND LENGTH(s.doc_comment) > 40"
        );

        $pdo->exec(
            "INSERT INTO fts_code (fqn, member, signature, doc, module, rel_path, line)
             SELECT gf.fqn, gf.short_name, gf.signature, gf.doc_comment, m.code, f.rel_path, gf.line
             FROM global_function gf
             JOIN module m ON m.id = gf.module_id
             JOIN file   f ON f.id = gf.file_id
             WHERE gf.doc_comment IS NOT NULL AND LENGTH(gf.doc_comment) > 40"
        );
    }

    /** @return list<string> */
    private static function tableStatements(): array
    {
        return [
            // ── Снимок сборки ────────────────────────────────────────────────
            'CREATE TABLE IF NOT EXISTS snapshot (
                id                      INTEGER PRIMARY KEY CHECK (id = 1),
                sm_version              TEXT,
                sm_version_date         TEXT,
                indexed_at              TEXT    NOT NULL,
                indexer_version         TEXT    NOT NULL,
                schema_version          INTEGER NOT NULL,
                file_count              INTEGER NOT NULL DEFAULT 0,
                total_bytes             INTEGER NOT NULL DEFAULT 0,
                parse_error_count       INTEGER NOT NULL DEFAULT 0,
                -- Состояние настройки на момент индексации. Индекс, построенный
                -- при выключенной настройке, недостоверен: 41% ядра теряется
                -- молча (R-001). Сервер обязан уметь распознать это постфактум,
                -- а не полагаться на то, что индексатор запустили правильно.
                short_open_tag_enabled  INTEGER NOT NULL,
                docs_crawled_at         TEXT,
                docs_source             TEXT,
                docs_source_revision    TEXT
            )',

            // ── Корни индексации ─────────────────────────────────────────────
            // Отдельная таблица, а не колонка в snapshot: FR-014 требует,
            // чтобы добавление корня было настройкой, а не переработкой модели.
            'CREATE TABLE IF NOT EXISTS index_root (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                path     TEXT    NOT NULL UNIQUE,
                label    TEXT    NOT NULL,
                is_core  INTEGER NOT NULL
            )',

            // ── Модуль: единица версионирования ──────────────────────────────
            'CREATE TABLE IF NOT EXISTS module (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                root_id       INTEGER NOT NULL REFERENCES index_root(id) ON DELETE CASCADE,
                code          TEXT    NOT NULL,
                version       TEXT,
                version_date  TEXT,
                -- module_file | sm_version | unknown. Разделение источников —
                -- требование Принципа II: unknown обязан оставаться отдельным
                -- состоянием, а не подменяться версией сборки.
                version_source TEXT   NOT NULL,
                rel_path      TEXT    NOT NULL,
                UNIQUE (root_id, code)
            )',

            // ── Файл: единица инкрементальности ──────────────────────────────
            'CREATE TABLE IF NOT EXISTS file (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                root_id       INTEGER NOT NULL REFERENCES index_root(id) ON DELETE CASCADE,
                module_id     INTEGER          REFERENCES module(id) ON DELETE CASCADE,
                -- Путь ОТНОСИТЕЛЬНО своего корня: именно это делает файл индекса
                -- переносимым между машинами (SC-006).
                rel_path      TEXT    NOT NULL,
                size          INTEGER NOT NULL,
                mtime         INTEGER NOT NULL,
                sha256        TEXT    NOT NULL,
                parsed_ok     INTEGER NOT NULL DEFAULT 1,
                had_short_open_tag INTEGER NOT NULL DEFAULT 0,
                seen_in_pass  INTEGER NOT NULL DEFAULT 1,
                UNIQUE (root_id, rel_path)
            )',

            // ── Ошибки разбора ───────────────────────────────────────────────
            // Отдельная таблица, а не колонка: ошибки обязаны оставаться
            // видимыми и пересчитываемыми (Принцип V запрещает их проглатывать).
            'CREATE TABLE IF NOT EXISTS parse_error (
                id       INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id  INTEGER NOT NULL REFERENCES file(id) ON DELETE CASCADE,
                message  TEXT    NOT NULL,
                line     INTEGER
            )',

            // ── Символы ──────────────────────────────────────────────────────
            'CREATE TABLE IF NOT EXISTS symbol (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                module_id        INTEGER NOT NULL REFERENCES module(id) ON DELETE CASCADE,
                file_id          INTEGER NOT NULL REFERENCES file(id) ON DELETE CASCADE,
                fqn              TEXT    NOT NULL,
                fqn_lower        TEXT    NOT NULL,
                short_name       TEXT    NOT NULL,
                short_name_lower TEXT    NOT NULL,
                kind             TEXT    NOT NULL,
                parent_fqn       TEXT,
                is_abstract      INTEGER NOT NULL DEFAULT 0,
                is_final         INTEGER NOT NULL DEFAULT 0,
                -- ФАКТ, прочитанный из @deprecated.
                is_deprecated    INTEGER NOT NULL DEFAULT 0,
                deprecated_note  TEXT,
                -- ЭВРИСТИКА по форме имени: d7 | legacy | unknown.
                -- Хранится отдельно от is_deprecated намеренно: у полей разная
                -- достоверность, и смешение сделало бы догадку неотличимой
                -- от факта (единственное задокументированное отклонение
                -- от Принципа III, см. plan.md → Complexity Tracking).
                api_style        TEXT    NOT NULL DEFAULT \'unknown\',
                doc_comment      TEXT,
                line             INTEGER NOT NULL
            )',

            'CREATE TABLE IF NOT EXISTS symbol_interface (
                symbol_id     INTEGER NOT NULL REFERENCES symbol(id) ON DELETE CASCADE,
                interface_fqn TEXT    NOT NULL
            )',

            // ── Псевдонимы class_alias ───────────────────────────────────────
            // Без этой таблицы \Bitrix\Sale\OrderTable не находится, хотя
            // используется повсеместно: объявлен он как
            // \Bitrix\Sale\Internals\OrderTable (R-006).
            'CREATE TABLE IF NOT EXISTS symbol_alias (
                id              INTEGER PRIMARY KEY AUTOINCREMENT,
                module_id       INTEGER NOT NULL REFERENCES module(id) ON DELETE CASCADE,
                file_id         INTEGER NOT NULL REFERENCES file(id) ON DELETE CASCADE,
                alias_fqn       TEXT    NOT NULL,
                alias_fqn_lower TEXT    NOT NULL,
                alias_short_lower TEXT  NOT NULL,
                target_fqn      TEXT    NOT NULL,
                line            INTEGER NOT NULL
            )',

            // ── Методы и параметры ───────────────────────────────────────────
            'CREATE TABLE IF NOT EXISTS method (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                symbol_id    INTEGER NOT NULL REFERENCES symbol(id) ON DELETE CASCADE,
                name         TEXT    NOT NULL,
                name_lower   TEXT    NOT NULL,
                visibility   TEXT    NOT NULL,
                is_static    INTEGER NOT NULL DEFAULT 0,
                is_abstract  INTEGER NOT NULL DEFAULT 0,
                is_final     INTEGER NOT NULL DEFAULT 0,
                return_type  TEXT,
                -- Отрендеренная сигнатура. Денормализация оправдана Принципом IV:
                -- это ровно то, что уходит агенту, и собирать её из параметров
                -- на каждый запрос значит платить за неизменяемое.
                signature    TEXT    NOT NULL,
                doc_comment  TEXT,
                is_deprecated INTEGER NOT NULL DEFAULT 0,
                line         INTEGER NOT NULL
            )',

            // ── Глобальные и namespaced-функции ──────────────────────────────
            'CREATE TABLE IF NOT EXISTS global_function (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                module_id        INTEGER NOT NULL REFERENCES module(id) ON DELETE CASCADE,
                file_id          INTEGER NOT NULL REFERENCES file(id) ON DELETE CASCADE,
                fqn              TEXT    NOT NULL,
                fqn_lower        TEXT    NOT NULL,
                short_name       TEXT    NOT NULL,
                short_name_lower TEXT    NOT NULL,
                signature        TEXT    NOT NULL,
                return_type      TEXT,
                doc_comment      TEXT,
                is_deprecated    INTEGER NOT NULL DEFAULT 0,
                line             INTEGER NOT NULL
            )',

            // Значение хранится как исходное представление AST, а не вычисляется.
            'CREATE TABLE IF NOT EXISTS class_constant (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                symbol_id   INTEGER NOT NULL REFERENCES symbol(id) ON DELETE CASCADE,
                name        TEXT    NOT NULL,
                name_lower  TEXT    NOT NULL,
                visibility  TEXT    NOT NULL,
                is_final    INTEGER NOT NULL DEFAULT 0,
                value_repr  TEXT    NOT NULL,
                doc_comment TEXT,
                line        INTEGER NOT NULL
            )',

            // Точки вызова извлекаются AST-визитором. Для $obj->method()
            // класс статически неизвестен и это фиксирует is_dynamic.
            'CREATE TABLE IF NOT EXISTS usage_reference (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                module_id           INTEGER NOT NULL REFERENCES module(id) ON DELETE CASCADE,
                file_id             INTEGER NOT NULL REFERENCES file(id) ON DELETE CASCADE,
                kind                TEXT    NOT NULL,
                target_fqn          TEXT,
                target_fqn_lower    TEXT,
                member_name         TEXT    NOT NULL,
                member_name_lower   TEXT    NOT NULL,
                context_fqn         TEXT,
                context_member      TEXT,
                is_dynamic          INTEGER NOT NULL DEFAULT 0,
                line                INTEGER NOT NULL
            )',

            // ── Документация ─────────────────────────────────────────────────
            // Отдельно от кода и БЕЗ привязки к модулю. Ревизия Git фиксирует
            // снимок самих статей; она не является версией установленного ядра.
            // Упоминания версий в тексте хранятся как справочный признак и НЕ
            // являются основанием для суждений о наличии символа в сборке.
            'CREATE TABLE IF NOT EXISTS doc_page (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                url              TEXT    NOT NULL UNIQUE,
                section          TEXT    NOT NULL,
                title            TEXT,
                content          TEXT    NOT NULL,
                fetched_at       TEXT    NOT NULL,
                etag             TEXT,
                content_sha256   TEXT    NOT NULL,
                version_mentions TEXT,
                -- Ссылки, найденные на странице. Нужны для обхода: при ответе
                -- 304 тело не приходит, и без сохранённых ссылок повторный
                -- сбор умирал бы на первой же закэшированной странице.
                links            TEXT,
                source_type      TEXT    NOT NULL DEFAULT \'web\',
                source_ref       TEXT,
                source_revision  TEXT
            )',

            // Полнотекстовый поиск по документации. unicode61 закрывает
            // требование FR-032 о кириллице.
            'CREATE VIRTUAL TABLE IF NOT EXISTS fts_doc USING fts5(
                title, content,
                content=doc_page, content_rowid=id,
                tokenize="unicode61 remove_diacritics 2"
            )',

            // ── Полнотекстовый поиск по коду ─────────────────────────────────
            // Обычная, а не внешняя таблица FTS: источником служит СОЕДИНЕНИЕ
            // symbol и method, а внешнее содержимое поддерживает только одну
            // таблицу. Дублирование текста — осознанная плата за возможность
            // искать по описанию задачи, когда точное имя неизвестно.
            //
            // Токенизатор unicode61: PHPDoc ядра русскоязычный примерно
            // наполовину, и trigram здесь дал бы худшее ранжирование
            // при большем размере.
            'CREATE VIRTUAL TABLE IF NOT EXISTS fts_code USING fts5(
                fqn, member, signature, doc,
                module UNINDEXED, rel_path UNINDEXED, line UNINDEXED,
                tokenize="unicode61 remove_diacritics 2"
            )',

            // ── Компоненты ───────────────────────────────────────────────────
            'CREATE TABLE IF NOT EXISTS component (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                module_id   INTEGER NOT NULL REFERENCES module(id) ON DELETE CASCADE,
                file_id     INTEGER NOT NULL REFERENCES file(id) ON DELETE CASCADE,
                namespace   TEXT    NOT NULL,
                name        TEXT    NOT NULL,
                full_name   TEXT    NOT NULL,
                title       TEXT,
                description TEXT,
                -- 0 => $arComponentParameters не задан литеральным массивом.
                -- Компонент всё равно в индексе: «нет параметров» и «параметры
                -- не извлеклись» — разные состояния (Принцип III).
                params_resolved INTEGER NOT NULL DEFAULT 1
            )',

            'CREATE TABLE IF NOT EXISTS component_param (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                component_id        INTEGER NOT NULL REFERENCES component(id) ON DELETE CASCADE,
                position            INTEGER NOT NULL,
                code                TEXT    NOT NULL,
                type                TEXT,
                default_repr        TEXT,
                -- 0 => значение вычисляется в рантайме через GetMessage()
                -- или COption::GetOptionString(). Ожидаемое состояние при
                -- запрете на выполнение кода (Принцип VI), а не ошибка.
                is_default_resolved INTEGER NOT NULL DEFAULT 1,
                parent_group        TEXT,
                multiple            INTEGER NOT NULL DEFAULT 0,
                values_repr         TEXT
            )',

            // ── REST-методы ──────────────────────────────────────────────────
            'CREATE TABLE IF NOT EXISTS rest_method (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                module_id  INTEGER NOT NULL REFERENCES module(id) ON DELETE CASCADE,
                file_id    INTEGER NOT NULL REFERENCES file(id) ON DELETE CASCADE,
                name       TEXT    NOT NULL,
                name_lower TEXT    NOT NULL,
                handler    TEXT,
                line       INTEGER NOT NULL
            )',

            // ── Зависимости модулей ──────────────────────────────────────────
            // Источник — фактические подключения Loader::includeModule().
            // Формальной декларации зависимостей у Битрикса нет (FR-011).
            'CREATE TABLE IF NOT EXISTS module_dependency (
                module_id       INTEGER NOT NULL REFERENCES module(id) ON DELETE CASCADE,
                depends_on_code TEXT    NOT NULL,
                occurrences     INTEGER NOT NULL DEFAULT 1,
                PRIMARY KEY (module_id, depends_on_code)
            )',

            // ── События ──────────────────────────────────────────────────────
            // module_id — модуль ФАЙЛА, в котором встретился вызов.
            // owner_module — модуль из первого аргумента вызова, то есть чьё
            // это событие. Они различаются, когда один модуль подписывается
            // на событие другого, и склеивать их нельзя: вопросы «кто бросает»
            // и «кто подписан» разные.
            'CREATE TABLE IF NOT EXISTS event (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                module_id     INTEGER NOT NULL REFERENCES module(id) ON DELETE CASCADE,
                file_id       INTEGER NOT NULL REFERENCES file(id) ON DELETE CASCADE,
                owner_module  TEXT    NOT NULL,
                name          TEXT    NOT NULL,
                name_lower    TEXT    NOT NULL,
                -- send | register
                kind          TEXT    NOT NULL,
                call_style    TEXT    NOT NULL,
                params_repr   TEXT,
                line          INTEGER NOT NULL
            )',

            // ── ORM ──────────────────────────────────────────────────────────
            // is_complete и incomplete_reason обязательны: схема, отданная как
            // полная при частичном извлечении, опаснее отсутствия схемы —
            // агент напишет код против неполной картины без единого сигнала.
            'CREATE TABLE IF NOT EXISTS orm_entity (
                id                INTEGER PRIMARY KEY AUTOINCREMENT,
                symbol_id         INTEGER NOT NULL REFERENCES symbol(id) ON DELETE CASCADE,
                table_name        TEXT,
                -- literal | unresolved | inherited
                table_name_source TEXT    NOT NULL,
                -- array | fluent | mixed | unresolved
                extraction_style  TEXT    NOT NULL,
                is_complete       INTEGER NOT NULL,
                incomplete_reason TEXT
            )',

            'CREATE TABLE IF NOT EXISTS orm_field (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                orm_entity_id       INTEGER NOT NULL REFERENCES orm_entity(id) ON DELETE CASCADE,
                position            INTEGER NOT NULL,
                name                TEXT    NOT NULL,
                data_type           TEXT,
                is_primary          INTEGER NOT NULL DEFAULT 0,
                is_required         INTEGER NOT NULL DEFAULT 0,
                is_autocomplete     INTEGER NOT NULL DEFAULT 0,
                default_repr        TEXT,
                -- 0 => значение задано замыканием. Отличать от «значения нет».
                is_default_resolved INTEGER NOT NULL DEFAULT 1,
                reference_target    TEXT,
                join_condition      TEXT,
                values_repr         TEXT
            )',

            'CREATE TABLE IF NOT EXISTS parameter (
                id           INTEGER PRIMARY KEY AUTOINCREMENT,
                method_id    INTEGER NOT NULL REFERENCES method(id) ON DELETE CASCADE,
                position     INTEGER NOT NULL,
                name         TEXT    NOT NULL,
                type         TEXT,
                -- Исходное текстовое представление, а не вычисленное значение:
                -- вычисление потребовало бы выполнения кода (Принцип VI).
                default_repr TEXT,
                by_ref       INTEGER NOT NULL DEFAULT 0,
                variadic     INTEGER NOT NULL DEFAULT 0
            )',
        ];
    }

    /** @return list<string> */
    private static function indexStatements(): array
    {
        return [
            // Самый горячий путь: агент почти всегда знает короткое имя
            // (OrderTable), а не полное.
            'CREATE INDEX IF NOT EXISTS idx_symbol_short_lower ON symbol(short_name_lower)',
            'CREATE INDEX IF NOT EXISTS idx_symbol_fqn_lower   ON symbol(fqn_lower)',
            'CREATE INDEX IF NOT EXISTS idx_symbol_module      ON symbol(module_id)',
            'CREATE INDEX IF NOT EXISTS idx_symbol_file        ON symbol(file_id)',
            'CREATE INDEX IF NOT EXISTS idx_symbol_iface       ON symbol_interface(symbol_id)',
            'CREATE INDEX IF NOT EXISTS idx_alias_lower        ON symbol_alias(alias_fqn_lower)',
            'CREATE INDEX IF NOT EXISTS idx_alias_short_lower  ON symbol_alias(alias_short_lower)',
            'CREATE INDEX IF NOT EXISTS idx_method_symbol      ON method(symbol_id)',
            'CREATE INDEX IF NOT EXISTS idx_method_name_lower  ON method(name_lower)',
            'CREATE INDEX IF NOT EXISTS idx_function_fqn_lower ON global_function(fqn_lower)',
            'CREATE INDEX IF NOT EXISTS idx_function_name      ON global_function(short_name_lower)',
            'CREATE INDEX IF NOT EXISTS idx_function_module    ON global_function(module_id)',
            'CREATE INDEX IF NOT EXISTS idx_constant_symbol    ON class_constant(symbol_id, name_lower)',
            'CREATE INDEX IF NOT EXISTS idx_usage_member       ON usage_reference(member_name_lower)',
            'CREATE INDEX IF NOT EXISTS idx_usage_target       ON usage_reference(target_fqn_lower, member_name_lower)',
            'CREATE INDEX IF NOT EXISTS idx_usage_module       ON usage_reference(module_id)',
            'CREATE INDEX IF NOT EXISTS idx_param_method       ON parameter(method_id, position)',
            'CREATE INDEX IF NOT EXISTS idx_orm_symbol         ON orm_entity(symbol_id)',
            'CREATE INDEX IF NOT EXISTS idx_event_name_lower   ON event(name_lower)',
            'CREATE INDEX IF NOT EXISTS idx_event_owner        ON event(owner_module, kind)',
            'CREATE INDEX IF NOT EXISTS idx_event_file         ON event(file_id)',
            'CREATE INDEX IF NOT EXISTS idx_component_fullname ON component(full_name)',
            'CREATE INDEX IF NOT EXISTS idx_component_name     ON component(name)',
            'CREATE INDEX IF NOT EXISTS idx_cparam_component   ON component_param(component_id, position)',
            'CREATE INDEX IF NOT EXISTS idx_rest_name_lower    ON rest_method(name_lower)',
            'CREATE INDEX IF NOT EXISTS idx_rest_module        ON rest_method(module_id)',
            'CREATE INDEX IF NOT EXISTS idx_orm_table          ON orm_entity(table_name)',
            'CREATE INDEX IF NOT EXISTS idx_ormfield_entity    ON orm_field(orm_entity_id, position)',
            'CREATE INDEX IF NOT EXISTS idx_file_relpath       ON file(rel_path)',
            'CREATE INDEX IF NOT EXISTS idx_file_module        ON file(module_id)',
            'CREATE INDEX IF NOT EXISTS idx_module_code        ON module(code)',
        ];
    }
}
