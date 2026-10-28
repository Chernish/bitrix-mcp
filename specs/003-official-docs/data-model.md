# Data Model: Версионные примечания документации

Схема БД 9 добавляет происхождение документации:

- `snapshot.docs_source`, `snapshot.docs_source_revision` — источник и commit всего снимка;
- `doc_page.source_type` — `git` или `web`;
- `doc_page.source_ref` — путь Markdown или URL;
- `doc_page.source_revision` — commit SHA, для web равен `NULL`.

`doc_page.version_mentions` содержит JSON формата 4:

```json
{
  "schema": 4,
  "notes": [{
    "version": "23.0.0",
    "subject_kind": "module",
    "module": "main",
    "subject": "Главный модуль",
    "heading": "HTTP-клиент",
    "statement": "С версии Главного модуля 23.0.0 ..."
  }]
}
```

`subject_kind`: `module`, `php`, `bitrixvm` или `unknown`. `module` присутствует
только при надёжном сопоставлении с кодом модуля.

MCP добавляет вычисляемые поля `installed_module_version` и `availability`.
Они не записываются в документацию, потому что относятся к текущему локальному
снимку и могут измениться после переиндексации ядра.
