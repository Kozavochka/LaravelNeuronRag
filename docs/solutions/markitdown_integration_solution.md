# MarkItDown Integration Solution

## Summary

Добавлена интеграция с внешним локальным сервисом `markitdown` (`http://localhost:8123`) для конвертации расширенных форматов документов в markdown перед текущим ingestion/chunking пайплайном.

Принятые правила:
- `fail-open`: базовые форматы `md/docx` доступны всегда;
- расширенные форматы доступны только при `markitdown /health = ok`;
- конвертация выполняется в runtime индексатора (queue job), а не в upload-request.

## Key Changes

1. Конфиг и env:
- `rag.markitdown.*` в `config/rag.php`
- новые env: `RAG_MARKITDOWN_ENABLED`, `RAG_MARKITDOWN_BASE_URL`, `RAG_MARKITDOWN_PORT=8123`, `RAG_MARKITDOWN_TIMEOUT`, `RAG_MARKITDOWN_HEALTH_TTL`, `RAG_MARKITDOWN_EXTENSIONS`

2. Domain services:
- `MarkitdownHttpClient` с контрактом `MarkitdownClientInterface`
- `DocumentUploadCapabilitiesService` для динамического списка разрешённых расширений
- `IntegrationEventLogger` + таблица `integration_events` для диагностики

3. Ingestion flow:
- `DocumentIndexingService` теперь:
  - для `md/docx` использует текущие extractors;
  - для расширенных форматов вызывает `markitdown /convert` и получает markdown для существующего chunking-пайплайна.

4. API:
- новый endpoint `GET /api/rag/capabilities`
- upload валидация (`POST /api/rag/documents`) использует динамические capabilities

5. Admin:
- upload-страница показывает текущий health и активный список расширений
- новая diagnostics-страница `/admin/integrations/markitdown` с текущим health и историей событий

## Test Plan

- Feature API:
  - capabilities endpoint возвращает health + dynamic extensions
  - extended upload принимается при `health=ok`
  - extended upload отклоняется при `health=down`
- Feature Admin:
  - страница интеграции рендерится и отображает события
- Unit:
  - индексатор сохраняет существующее поведение embedding/raw_content и принимает новый dependency

## Assumptions

- markitdown доступен локально по `localhost:8123`
- контракт markitdown:
  - `GET /health` -> `{ "status": "ok" }`
  - `POST /convert` -> JSON с `markdown`
- текущая модель хранения исходного файла не меняется: сохраняется оригинал, конвертация выполняется on-demand в job.
