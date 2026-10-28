# Implementation Plan: Официальная документация с версиями

**Branch**: `003-official-docs` | **Date**: 2026-08-22 | **Spec**: [spec.md](./spec.md)

## Summary

Нормализовать sitemap в фактические `/pages/` URL; извлекать примечания на
уровне абзацев вместе с заголовком и субъектом; хранить JSON версии 2 в
существующем `doc_page.version_mentions`; обогащать `bitrix_search_docs`
сравнением с версиями модулей основного корня.

## Technical Context

**Indexer**: PHP 8.4, DOMDocument/DOMXPath, curl, PDO SQLite

**Server**: Node.js 22, TypeScript 5.7, better-sqlite3

**Storage**: схема 7, JSON `{schema:3,notes:[...]}` в существующей колонке

**Testing**: PHPUnit 11, PHPStan, PHPCS, node:test, TypeScript, ESLint

**Constraints**: read-only MCP, внешний источник не блокирует код, 500 мс между
запросами, ответ ≤2000 символов

## Constitution Check

- I: `/api/` не используется для утверждений о существовании; конфликт решает код.
- II: module note сравнивается с module version, сборка остаётся в provenance.
- III: неизвестный субъект остаётся unknown, а не угадывается.
- IV: статья возвращается фрагментом; нижние результаты явно усекаются.
- V: JSON формата версионирован, порядок обхода/заметок воспроизводим.
- VI: внешний сайт только читается, ядро и рабочая БД не затрагиваются.

Отклонений нет.

## Files

```text
indexer/src/Docs/DocsCrawler.php
indexer/src/Docs/ContentExtractor.php
indexer/tests/Unit/DocsCrawlerTest.php
server/src/tools/search-docs.ts
server/tests/unit/docs-version-notes.test.ts
specs/003-official-docs/
```
