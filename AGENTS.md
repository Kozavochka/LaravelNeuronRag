# AGENTS.md

## Purpose

This repository is a Laravel 13 API-only RAG application.

Core stack:
- PHP `^8.3`
- Laravel `^13`
- PostgreSQL + `pgvector`
- Neuron AI `^3.3`
- Neuron Laravel `^1.1`
- OpenRouter for chat completions
- OpenRouter-compatible embeddings or Ollama embeddings
- Queue-backed document indexing

The current implementation is centered on:
- upload `.md` and `.docx` documents
- extract and chunk text
- embed chunks
- store vectors in PostgreSQL
- answer questions through Neuron RAG runtime

## Current Runtime Shape

Primary HTTP routes live in `routes/api.php`:
- `GET /api/rag/documents`
- `POST /api/rag/documents`
- `GET /api/rag/documents/{document}`
- `POST /api/rag/documents/{document}/reindex`
- `POST /api/rag/chat`

Primary runtime entrypoints:
- `app/Http/Controllers/Rag/DocumentController.php`
- `app/Http/Controllers/Rag/ChatController.php`
- `app/Providers/AppServiceProvider.php`
- `app/Neuron/DocumentRAG.php`
- `app/Neuron/VectorStore/PgVectorStore.php`
- `app/Domain/Documents/Services/Indexing/DocumentIndexingService.php`
- `config/rag.php`

## Working Assumptions

- This is API-only. Do not add web UI unless explicitly requested.
- Queue processing is required for document indexing.
- PostgreSQL is the intended production-like path. SQLite exists only for tests and basic local validation.
- The current embeddings setup is expected to work with `1024` dimensions.
- The repository may not be a git checkout in the working environment. Do not assume `git status` or `git diff` is available.

## Required Local Services

For the project to function end-to-end, all of the following must be available:
- PostgreSQL with `pgvector`
- Laravel app server
- Laravel queue worker
- Valid `OPENROUTER_API_KEY`

Typical local startup:

```bash
docker compose up -d
php artisan migrate
php artisan serve
php artisan queue:work
```

Useful checks:

```bash
php artisan route:list | rg rag
php artisan test
composer check-platform-reqs
```

## Configuration Notes

The RAG configuration is centralized in `config/rag.php`.

Important env variables:
- `DB_CONNECTION=pgsql`
- `RAG_RUNTIME_AVAILABLE=true`
- `RAG_EMBEDDING_PROVIDER`
- `RAG_EMBEDDING_MODEL`
- `RAG_EMBEDDING_DIMENSIONS`
- `RAG_EMBEDDING_BASE_URL`
- `OPENROUTER_API_KEY`
- `OPENROUTER_BASE_URL`
- `OPENROUTER_MODEL`

Current supported document extensions:
- `md`
- `docx`

Upload validation is extension-based, not mime-type based. This was changed because Postman often sends unstable content types for `.md` uploads.

## Document Lifecycle

Upload flow:
1. `POST /api/rag/documents`
2. `DocumentImportService` stores the uploaded file and creates a `documents` row with status `uploaded`
3. `ProcessDocumentJob` is dispatched
4. `DocumentIndexingService` extracts, sanitizes, chunks, embeds, and stores vectors
5. document status becomes `indexed` or `failed`

Question-answer flow:
1. `POST /api/rag/chat`
2. `RagChatRuntime` resets `DocumentRAG`
3. optional `document_id` filter is applied
4. `DocumentRAG` retrieves chunks via `PgVectorStore`
5. OpenRouter LLM answers using retrieved context
6. query + source links are logged

## Important Implementation Details

### Embeddings Provider

Embeddings provider selection is wired in `app/Providers/AppServiceProvider.php`.

Current behavior:
- `openrouter`, `openai-like`, `openai_like` -> `OpenAILikeEmbeddings`
- everything else -> `OllamaEmbeddingsProvider`

When changing embedding model:
- check vector dimensions first
- if dimensions change, current `document_chunks.embedding` column may become incompatible
- full document reindex is required after embedding model changes

### Neuron Runtime

`app/Neuron/DocumentRAG.php` must call `parent::__construct()`.

This is not optional. Without it, Neuron `Workflow` internals do not initialize `workflowId`, and `chat()` fails at runtime.

### Vector Store

`app/Neuron/VectorStore/PgVectorStore.php` stores chunk `content` separately from `embedding_text`.

The current indexing path intentionally:
- uses enriched text for embeddings
- preserves raw chunk text in metadata as `raw_content`
- writes raw chunk text into the DB `content` column

Do not casually collapse these two concepts.

### Chunk Filtering

`RAG_MIN_CHUNK_CHARS` matters in real usage.

If a test or smoke document is too short, it may index as zero active chunks even when upload and embedding calls succeed. This is expected behavior with the current filter settings.

## Known Project Hazards

### Duplicate Code Paths

There are parallel namespaces under:
- `app/Domain/Documents/Services/...`
- `app/Domain/Documents/...`

There are also duplicate migration sets:
- `database/migrations/2026_04_26_0001xx_*`
- `database/migrations/2026_04_26_2001xx_*`

Current working runtime is based primarily on:
- `app/Domain/Documents/Services/...`
- `app/Domain/Documents/Jobs/ProcessDocumentJob.php`
- controllers in `app/Http/Controllers/Rag`
- `app/Neuron/*`

Do not assume both trees are equally current.

Before editing any document ingestion or chunking code:
- verify which namespace is actually used by the container bindings
- verify which class is referenced by controllers/jobs
- run tests after changes

### Tests Do Not Cover Every Runtime Path

Several runtime bugs were previously found despite green tests:
- Neuron workflow constructor not being called
- DTO property mismatch: `text` vs `content`
- named argument mismatch in text extractors
- section path representation mismatch across chunking layers
- `.md` upload validation failing in Postman due to mime-based validation

After changes in ingestion or RAG runtime, do not stop at `php artisan test`.
Also run a real smoke path when possible:
- upload or create a markdown document
- index it
- ask a question

## Testing Guidance

Baseline:

```bash
php artisan test
```

When touching upload/indexing/chat:
- confirm route list
- confirm queue worker is running
- confirm document status reaches `indexed`
- confirm chat returns JSON

If debugging runtime:
- check `documents.status`
- check `documents.error_message`
- inspect `document_chunks` count for the document

## API Usage Notes

### Uploads

Uploads must be multipart file uploads using the `file` field.

Example:

```bash
curl -X POST http://127.0.0.1:8000/api/rag/documents \
  -H "Accept: application/json" \
  -F "file=@/absolute/path/to/document.md"
```

In Postman:
- method: `POST`
- body: `form-data`
- field name: `file`
- field type: `File`

### Chat

`document_id` is optional.

- with `document_id`: retrieval is limited to one document
- without `document_id`: retrieval searches across all indexed documents

Example:

```json
{
  "question": "Что сказано про pgvector?",
  "document_id": 1
}
```

## Change Rules For Future Agents

- Prefer editing existing RAG wiring rather than introducing a parallel path.
- Keep `DocumentRAG`, `PgVectorStore`, and `DocumentIndexingService` aligned.
- If you change DTO fields, verify every caller, not just the compiler-visible ones.
- If you change upload validation, verify Postman-style `.md` upload, not just framework fakes.
- If you change embeddings model or dimensions, explicitly plan reindexing.
- Do not remove `raw_content` preservation without checking retrieval output quality.
- Do not rely on mime type alone for markdown acceptance.

## Recommended First Checks Before Any RAG Change

```bash
php artisan route:list | rg rag
php artisan test
php artisan tinker --execute="dump(config('rag'));"
```

Then inspect:
- `app/Providers/AppServiceProvider.php`
- `app/Neuron/DocumentRAG.php`
- `app/Domain/Documents/Services/Indexing/DocumentIndexingService.php`
- `app/Neuron/VectorStore/PgVectorStore.php`
