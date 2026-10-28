# bitrix-mcp — индексатор ядра 1С-Битрикс и MCP-сервер
#
# КРИТИЧНО: каждая цель, запускающая PHP, обязана передавать -d short_open_tag=1.
#
# 41% файлов ядра (27 652 из 67 835) открываются коротким тегом `<?`. При выключенной
# настройке парсер не выдаёт ошибку — он возвращает такой файл как InlineHTML, то есть
# синтаксически корректный результат с нулём объявлений. На выборке 3000 файлов так
# терялось 2122 файла и 15% классов, причём индексация выглядела успешной.
# Подробности: specs/001-bitrix-core-mcp/research.md, R-001.
#
# Полагаться на php.ini машины нельзя: в целевом окружении настройка выключена
# по умолчанию. Индексатор дополнительно откажется стартовать (код возврата 2),
# но флаг здесь — первая линия обороны, а не дублирование.

PHP := php -d short_open_tag=1
PHP_ANALYSIS := php -d short_open_tag=1 -d memory_limit=512M
INDEXER := $(PHP) indexer/bin/index.php

BITRIX_CORE_ROOT ?= /Users/sergejcernomordov/stroka/bp/www/bitrix
BITRIX_MCP_INDEX ?= ./index.sqlite

export BITRIX_CORE_ROOT
export BITRIX_MCP_INDEX

.PHONY: help build update docs status verify snapshots snapshot-archive snapshot-use snapshot-delete \
        test test-indexer test-server lint lint-indexer lint-server server-build server-http clean

help:
	@echo "Цели:"
	@echo "  make build          полная индексация ядра"
	@echo "  make update         инкрементальная переиндексация"
	@echo "  make docs           сбор документации"
	@echo "  make status         состояние индекса"
	@echo "  make verify         сверка с контрольной выборкой"
	@echo "  make snapshots      список версий ядра"
	@echo "  make snapshot-use SNAPSHOT=<id>      активировать версию"
	@echo "  make snapshot-delete SNAPSHOT=<id>   удалить архивную версию"
	@echo "  make test           все тесты"
	@echo "  make lint           статический анализ"
	@echo "  make server-build   сборка MCP-сервера"
	@echo "  make server-http    HTTP MCP с Bearer-авторизацией"
	@echo ""
	@echo "BITRIX_CORE_ROOT = $(BITRIX_CORE_ROOT)"
	@echo "BITRIX_MCP_INDEX = $(BITRIX_MCP_INDEX)"

build:
	$(INDEXER) build

update:
	$(INDEXER) update

docs:
	$(INDEXER) docs

status:
	$(INDEXER) status

verify:
	$(INDEXER) verify

snapshots:
	$(INDEXER) snapshots

snapshot-archive:
	$(INDEXER) snapshot-archive

snapshot-use:
	$(INDEXER) snapshot-use --id=$(SNAPSHOT)

snapshot-delete:
	$(INDEXER) snapshot-delete --id=$(SNAPSHOT)

test: test-indexer test-server

test-indexer:
	cd indexer && $(PHP) vendor/bin/phpunit

test-server:
	cd server && npm test

lint: lint-indexer lint-server

lint-indexer:
	cd indexer && $(PHP_ANALYSIS) vendor/bin/phpstan analyse --no-progress
	cd indexer && $(PHP) vendor/bin/phpcs

lint-server:
	cd server && npx tsc --noEmit
	cd server && npx eslint .

server-build:
	cd server && npm run build

server-http:
	cd server && npm run start:http

clean:
	rm -f $(BITRIX_MCP_INDEX)
	rm -rf server/dist
