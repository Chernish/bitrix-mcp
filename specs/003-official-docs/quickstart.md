# Quickstart: официальная документация

```bash
make docs
make test
make lint
make server-build
```

Проверка данных:

```bash
sqlite3 index.sqlite "select count(*) from doc_page;"
sqlite3 index.sqlite "select sum(json_array_length(json_extract(version_mentions,'$.notes'))) from doc_page;"
sqlite3 index.sqlite "select docs_source, docs_source_revision from snapshot;"
```

Контроль MCP:

- `PSR-18 HttpClient` → `main 23.300.0`, `available`;
- `метод walk коллекции` → `main 26.0.0`, `requires_newer_module`;
- `защищенные cookie` → `main 20.5.400`, `available`.

## Зафиксированный результат 2026-08-22

- Git-снимок: 199 Markdown-страниц, commit `8d9c2cc48272c5de2cb172ed4eee2b7601f6557c`.
- Первый импорт: обновлено 199; повторный: обновлено 0, без изменений 199.
- Версионные примечания: 34 (31 module, 1 BitrixVM, 1 PHP, 1 unknown).
- Индексатор: 74 теста, 360 assertions.
- Сервер: 61 тест.
- PHPStan, PHPCS, TypeScript, ESLint и production build: успешно.
- SQLite `integrity_check`: `ok`; smoke-тест production `dist`: успешно.
