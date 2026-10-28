# Implementation Plan: Ранжирование полнотекстового поиска

**Branch**: `002-search-ranking` | **Date**: 2026-08-22 | **Spec**: [spec.md](./spec.md)

## Summary

Оба MCP-поиска используют один Unicode-разборщик запроса и один построитель
FTS5-запроса. Покрытие считается по всем уникальным токенам без предварительного
top-N, после чего ничьи разрешаются `bm25` и `rowid`. Пустые после очистки
запросы получают структурированную причину `invalid_query`; лимиты нормализуются.

## Technical Context

**Language/Version**: TypeScript 5.7, Node.js 22

**Primary Dependencies**: better-sqlite3 11, SQLite FTS5, MCP SDK 1.x

**Storage**: существующий `index.sqlite`, схема 7 без миграции

**Testing**: `node:test`, синтетический FTS5-индекс и реальный снимок ядра

**Target Platform**: локальный stdio MCP-сервер

**Performance Goals**: интерактивный ответ; не более +20% к прежнему бюджету,
ответ не более 2000 символов на контрольных запросах

**Constraints**: точное преимущество полного совпадения, fallback на частичные,
не более 12 токенов, не более 10 результатов кода и 5 результатов документации

**Scale/Scope**: 56 629 строк `fts_code`, 155 текущих страниц `fts_doc`, 20
контрольных многословных запросов

## Constitution Check

- I: поиск возвращает только строки снимка; ошибок БД не маскирует под «не найдено».
- II: формат результатов и общий provenance не изменены.
- III: токены не теряются молча; пустой очищенный запрос имеет явную причину.
- IV: лимиты нормализованы, snippet сокращён, контракт 2000 символов проходит.
- V: порядок заканчивается стабильным `rowid`; повторный прогон совпадает.
- VI: сервер по-прежнему открывает индекс только для чтения.

Нарушений и требующих обоснования отклонений нет.

## Project Structure

```text
server/src/db/search.ts                 # общие разбор и ранжирование
server/src/tools/search-code.ts         # поиск кода
server/src/tools/search-docs.ts         # поиск документации
server/tests/fixtures/search-queries.json
server/tests/unit/search-ranking.test.ts
server/tests/unit/cyrillic-search.test.ts
specs/002-search-ranking/
```

**Structure Decision**: изменение локализовано в существующем сервере; схема и
индексатор не меняются.

## Complexity Tracking

Не требуется: конституционных отклонений нет.
