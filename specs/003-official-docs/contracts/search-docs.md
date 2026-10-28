# Contract: version_notes в bitrix_search_docs

```json
{
  "since": "26.0.0",
  "subject_kind": "module",
  "subject": "Главный модуль",
  "module": "main",
  "installed_module_version": "25.100.300",
  "availability": "requires_newer_module",
  "heading": "Обработать элементы коллекции",
  "statement": "Метод walk ... доступен с версии 26.0.0 главного модуля."
}
```

`availability`:

- `available` — установленная версия распознанного модуля не ниже `since`;
- `requires_newer_module` — установленная версия ниже;
- `unknown` — субъект/модуль или сравнимая версия не определены.

При превышении 2000 символов сохраняются верхние результаты, а ответ получает
`truncated:true` и `total_ranked`. Примечания лучшего результата не скрываются.
