# Архитектура LaravelNeuronRag

## 1. Назначение

Проект реализует API-only RAG-сервис на Laravel, который:

1. принимает `.md` и `.docx` документы;
2. извлекает и нормализует текст;
3. разбивает текст на чанки;
4. получает embeddings;
5. сохраняет чанки и векторы в PostgreSQL + `pgvector`;
6. отвечает на вопросы по одному документу или по всей коллекции через Neuron RAG runtime.

Целевая модель использования:
- загрузка документов через API;
- асинхронная индексация через Laravel Queue;
- retrieval + answer generation через OpenRouter.

---

## 2. Технологический стек

- PHP `^8.3`
- Laravel `^13`
- PostgreSQL `16+`
- `pgvector`
- `neuron-core/neuron-ai ^3.3`
- `neuron-core/neuron-laravel ^1.1`
- `phpoffice/phpword` для `.docx`
- `league/commonmark` в составе проекта для markdown-related сценариев
- Laravel Queue с `database` driver

Текущая рабочая конфигурация:
- chat provider: OpenRouter
- embeddings provider: OpenRouter-compatible embeddings
- vector dimensions: `1024`

---

## 3. Контекстная схема

```text
Client / Postman / Frontend
        |
        v
Laravel API
        |
        +--> POST /api/rag/documents
        |        |
        |        +--> DocumentImportService
        |        +--> documents row
        |        +--> ProcessDocumentJob -> Queue
        |
        +--> POST /api/rag/chat
                 |
                 +--> RagChatRuntime
                 +--> DocumentRAG (Neuron)
                 +--> PgVectorStore (vector candidates)
                 +--> RerankPostProcessor (keyword reranking)
                 +--> OpenRouter LLM

Queue Worker
    |
    +--> ProcessDocumentJob
            |
            +--> DocumentIndexingService
                    |
                    +--> TextExtractorFactory
                    +--> RagTextSanitizer
                    +--> Chunkers
                    +--> ChunkMetadataEnricher
                    +--> ChunkFilter
                    +--> EmbeddingsProviderInterface
                    +--> PgVectorStore

PostgreSQL + pgvector
    |
    +--> documents
    +--> document_versions
    +--> document_chunks
    +--> rag_queries
    +--> rag_query_chunks
```

---

## 4. Основные подсистемы

### 4.1 HTTP API

Маршруты:

- `GET /api/rag/documents`
- `POST /api/rag/documents`
- `GET /api/rag/documents/{document}`
- `POST /api/rag/documents/{document}/reindex`
- `POST /api/rag/chat`

Основные контроллеры:
- `app/Http/Controllers/Rag/DocumentController.php`
- `app/Http/Controllers/Rag/ChatController.php`

Особенности:
- API работает без auth в текущей версии.
- Upload принимает multipart file field `file`.
- `document_id` в `/api/rag/chat` необязателен.

---

### 4.2 Ingestion и индексация

Основной поток загрузки:

```text
POST /api/rag/documents
-> validation
-> DocumentImportService
-> запись в documents
-> dispatch(ProcessDocumentJob)
```

Фоновая обработка:

```text
ProcessDocumentJob
-> DocumentIndexingService
-> extractor
-> sanitizer
-> chunker
-> metadata enrichment
-> chunk filtering
-> embeddings
-> PgVectorStore::addDocuments()
```

Ключевые классы:
- `app/Domain/Documents/Services/DocumentImportService.php`
- `app/Domain/Documents/Jobs/ProcessDocumentJob.php`
- `app/Domain/Documents/Services/Indexing/DocumentIndexingService.php`

Поддерживаемые форматы:
- `.md`
- `.docx`

Важно:
- валидация upload сделана по расширению файла, а не по mime-type;
- это нужно, потому что Postman и некоторые клиенты отправляют нестабильный `Content-Type` для `.md`.

---

### 4.3 Text extraction и chunking

Извлечение текста:
- `MarkdownTextExtractor`
- `DocxTextExtractor`
- `TextExtractorFactory`

Нормализация:
- `RagTextSanitizer`

Chunking:
- `MarkdownAwareChunker` для markdown-структуры
- `RecursiveTextChunker` для общего разбиения текста

Постобработка чанков:
- `ChunkMetadataEnricher`
- `ChunkFilter`

Текущая логика:
- markdown старается сохранить иерархию секций;
- chunks получают metadata, включая `document_id`, `document_version_id`, `document_title`, `section_path`;
- короткие и нежелательные чанки фильтруются;
- `RAG_MIN_CHUNK_CHARS` напрямую влияет на то, останутся ли у документа активные чанки.

---

### 4.4 Embeddings слой

Embeddings резолвятся через DI в `AppServiceProvider`.

Текущая стратегия:
- если `RAG_EMBEDDING_PROVIDER` = `openrouter` или OpenAI-like вариант, используется `OpenAILikeEmbeddings`;
- иначе используется `OllamaEmbeddingsProvider`.

Текущий рабочий embedding model:
- `nvidia/llama-nemotron-embed-vl-1b-v2:free`

Важное ограничение:
- размерность embeddings должна совпадать с размерностью колонки `document_chunks.embedding`;
- в текущем проекте это `1024`.

Следствие:
- смена embedding-модели может потребовать полной переиндексации;
- если размерность изменится, потребуется миграция схемы.

---

### 4.5 Vector store

Собственный vector store:
- `app/Neuron/VectorStore/PgVectorStore.php`

Назначение:
- сохранять чанки и embeddings в `document_chunks`;
- выполнять similarity search через `pgvector`;
- поддерживать soft-deactivate старых чанков по документу;
- применять фильтрацию по `document_id` и `top_k`.

Текущая retrieval-семантика:
- vector search запрашивает candidate-набор: `candidate_k = max(requested_top_k, rag.retrieval.vector_candidates)`;
- финальный размер контекста задаётся после reranking: `final_k = requested_top_k ?? rag.retrieval.rerank_top_k`.

Особенность хранения:
- embedding строится по enriched text;
- в колонке `content` сохраняется raw chunk text;
- raw text дублируется в metadata как `raw_content`.

Это сделано намеренно, чтобы:
- retrieval опирался на семантически обогащённый embedding text;
- в ответах и source snippets возвращался оригинальный текст чанка.

---

### 4.6 Neuron RAG runtime

Основной runtime-класс:
- `app/Neuron/DocumentRAG.php`

Он отвечает за:
- provider для LLM;
- embeddings provider;
- vector store;
- retrieval filters;
- post-processing retrieved documents.

Ключевые свойства текущей реализации:
- RAG runtime state reset перед каждым вопросом;
- поддержка `document_id` filter;
- поддержка override `top_k` как финального размера контекста;
- двухэтапный retrieval pipeline:
  - vector retrieval top-N кандидатов (`PgVectorStore`);
  - keyword-based reranking (`RerankPostProcessor` + `SimpleKeywordReranker`);
  - ограничение контекста (`LimitContextPostProcessor`);
- retrieved documents сохраняются в buffer для последующего логирования источников;
- инструкции заставляют модель отвечать по-русски и не выдумывать факты.

Критически важная деталь:
- `DocumentRAG` обязан вызывать `parent::__construct()`;
- без этого Neuron `Workflow` не инициализируется корректно.

---

### 4.7 Query runtime и логирование

Сервис вопросов:
- `app/Domain/Rag/Services/RagChatRuntime.php`

Он:
- принимает вопрос;
- опционально применяет `document_id`;
- запускает `DocumentRAG`;
- преобразует найденные источники в DTO;
- логирует запрос и связи с чанками;
- сохраняет метрики, включая `rerank_ms`.

Логирование:
- `rag_queries`
- `rag_query_chunks`

Это даёт:
- историю вопросов;
- трассировку, какие чанки участвовали в ответе;
- latency/cost metrics на этапах embedding, vector search, rerank, LLM.

---

## 5. Модель данных

Основные таблицы:

### `documents`

Хранит:
- метаданные загруженного документа;
- путь к файлу;
- статус индексации;
- `content_hash`;
- `error_message`.

### `document_versions`

Хранит:
- версионность текста документа;
- `version_hash`;
- raw и normalized text;
- metadata версии;
- статус обработки версии.

### `document_chunks`

Хранит:
- чанки документа;
- metadata чанка;
- `embedding`;
- `content_hash`;
- `section_path`;
- `is_active`.

### `rag_queries`

Хранит:
- вопрос;
- ответ;
- llm provider/model;
- embedding model;
- `top_k`;
- metadata.

### `rag_query_chunks`

Хранит:
- связь между `rag_queries` и `document_chunks`;
- `distance`;
- `score`;
- `rerank_score`;
- `rank`.

---

## 6. Последовательности выполнения

### 6.1 Upload и indexing

```text
Client
-> POST /api/rag/documents
-> DocumentController
-> DocumentImportService
-> documents(status=uploaded)
-> ProcessDocumentJob queued

Queue worker
-> ProcessDocumentJob
-> DocumentIndexingService
-> extract text
-> sanitize
-> split to chunks
-> enrich metadata
-> filter chunks
-> embed chunks
-> deactivate old chunks for document
-> insert new chunks
-> document_versions(status=indexed)
-> documents(status=indexed)
```

### 6.2 Chat

```text
Client
-> POST /api/rag/chat
-> ChatController
-> RagChatRuntime
-> DocumentRAG.resetRuntimeState()
-> optional filter by document_id
-> embed question
-> PgVectorStore.similaritySearch()
-> retrieved chunks
-> OpenRouter LLM
-> answer
-> save rag_queries
-> save rag_query_chunks
-> return answer + sources
```

---

## 7. Конфигурация и внешние зависимости

Основной конфиг:
- `config/rag.php`

Критичные env:
- `DB_CONNECTION`
- `DB_HOST`
- `DB_PORT`
- `DB_DATABASE`
- `DB_USERNAME`
- `DB_PASSWORD`
- `QUEUE_CONNECTION`
- `RAG_RUNTIME_AVAILABLE`
- `RAG_EMBEDDING_PROVIDER`
- `RAG_EMBEDDING_MODEL`
- `RAG_EMBEDDING_DIMENSIONS`
- `RAG_EMBEDDING_BASE_URL`
- `OPENROUTER_API_KEY`
- `OPENROUTER_BASE_URL`
- `OPENROUTER_MODEL`

Локально должны работать:
- PostgreSQL + `vector` extension
- Laravel app server
- queue worker

---

## 8. Наблюдаемые ограничения и технический долг

### 8.1 Дублирование кода

В проекте есть параллельные деревья классов:
- `app/Domain/Documents/Services/...`
- `app/Domain/Documents/...`

Также есть дубли миграций:
- `2026_04_26_0001xx_*`
- `2026_04_26_2001xx_*`

Фактическая рабочая ветка сейчас опирается в первую очередь на:
- `app/Domain/Documents/Services/...`
- `app/Domain/Documents/Jobs/ProcessDocumentJob.php`
- `app/Http/Controllers/Rag/...`
- `app/Neuron/...`

Это нужно учитывать перед любым рефакторингом.

### 8.2 Неполное покрытие runtime тестами

Во время реализации уже всплывали runtime-баги, которые не ловились тестами:
- несоответствие `text` vs `content` в DTO;
- неверные named arguments;
- проблема с инициализацией Neuron workflow;
- слишком жёсткая mime-based валидация `.md`;
- несовпадение представления `sectionPath` между слоями.

Вывод:
- одного `php artisan test` недостаточно;
- после изменений в ingestion/runtime нужен реальный smoke-flow.

### 8.3 Текущая версия не содержит

Сейчас вне области реализации:
- auth и multi-tenant доступ;
- UI;
- PDF ingestion;
- OCR;
- reranking;
- observability metrics latency/cost;
- ACL по документам;
- background orchestration уровня Horizon.

---

## 9. Рекомендации по развитию

Следующие логичные шаги:

1. Нормализовать проект и удалить/слить дублирующиеся namespace-ветки.
2. Добавить расширенные метрики в `rag_queries`:
   - latency per stage;
   - token usage;
   - estimated cost.
3. Добавить integration/smoke tests для реального upload -> index -> chat пути.
4. Ввести auth и доступ к документам.
5. Добавить поддержку дополнительных форматов документов.
6. Вынести runtime runbook в README для обычного пользователя API.

---

## 10. Ключевые файлы реализации

- `routes/api.php`
- `config/rag.php`
- `app/Providers/AppServiceProvider.php`
- `app/Http/Controllers/Rag/DocumentController.php`
- `app/Http/Controllers/Rag/ChatController.php`
- `app/Neuron/DocumentRAG.php`
- `app/Neuron/VectorStore/PgVectorStore.php`
- `app/Domain/Documents/Services/DocumentImportService.php`
- `app/Domain/Documents/Jobs/ProcessDocumentJob.php`
- `app/Domain/Documents/Services/Indexing/DocumentIndexingService.php`
- `app/Domain/Rag/Services/RagChatRuntime.php`

Этот документ описывает фактическую архитектуру проекта после реализации и отладки рабочего RAG pipeline.
