# MCP Tools Contract

Новые инструменты:

- `bitrix_list_modules`
- `bitrix_get_module`
- `bitrix_list_module_api`
- `bitrix_list_components`
- `bitrix_get_class_hierarchy`
- `bitrix_get_class_constants`
- `bitrix_find_implementations`
- `bitrix_list_data_managers`
- `bitrix_find_events`
- `bitrix_find_callable`
- `bitrix_find_usages`
- `bitrix_get_doc_page`
- `bitrix_find_namespace`

Общие инварианты:

- каждый ответ обёрнут в `provenance`;
- многомодульные элементы сами несут `module`, `module_version`, `version_unknown`;
- все списки имеют `total`, `truncated`, `limit`/`offset`;
- not found не заменяется похожим ответом;
- ни один инструмент не возвращает целый PHP-файл.
