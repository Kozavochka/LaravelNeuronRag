# Техническое решение: RAG-пет-проект на Laravel + Neuron AI + PostgreSQL/pgvector

## 0. Цель проекта

Нужно реализовать RAG-систему, которая умеет:

1. Загружать документы `.md` и `.docx`.
2. Извлекать из них текст.
3. Очищать и нормализовать текст.
4. Разбивать текст на чанки.
5. Обогащать чанки метаданными.
6. Получать embeddings для каждого чанка.
7. Сохранять чанки и векторы в PostgreSQL через `pgvector`.
8. При пользовательском вопросе:
   - получать embedding вопроса;
   - искать похожие чанки;
   - собирать контекст;
   - отправлять контекст и вопрос в LLM;
   - возвращать ответ и источники.

Основной стек:

```text
Laravel 11/12
PHP 8.3+
PostgreSQL 16/17 локально
pgvector
Neuron AI
Neuron Laravel
Ollama для локальных embeddings
OpenRouter для бесплатных облачных LLM
PHPWord для чтения .docx
league/commonmark для чтения .md
Laravel Queue для фоновой индексации
```

Neuron AI — PHP-фреймворк для agentic/RAG-приложений. Он предоставляет слой для AI providers, embeddings providers, RAG, vector stores, memory/history и orchestration.  
Официальный репозиторий: <https://github.com/neuron-core/neuron-ai>  
Laravel-пакет: <https://github.com/neuron-core/neuron-laravel>  
Документация: <https://docs.neuron-ai.dev/>

---

## 1. Итоговая архитектура

```text
┌──────────────────────────────┐
│ Laravel App                  │
│ UI / API / Auth / Documents  │
└───────────────┬──────────────┘
                │
                ▼
┌──────────────────────────────┐
│ Document Upload              │
│ .md / .docx                  │
└───────────────┬──────────────┘
                │
                ▼
┌──────────────────────────────┐
│ Laravel Job                  │
│ ProcessDocumentJob           │
└───────────────┬──────────────┘
                │
                ▼
┌──────────────────────────────┐
│ Text Extraction              │
│ MarkdownExtractor            │
│ DocxExtractor                │
└───────────────┬──────────────┘
                │
                ▼
┌──────────────────────────────┐
│ Text Normalization           │
│ RagTextSanitizer             │
└───────────────┬──────────────┘
                │
                ▼
┌──────────────────────────────┐
│ Chunking                     │
│ MarkdownAwareChunker         │
│ RecursiveTextChunker         │
└───────────────┬──────────────┘
                │
                ▼
┌──────────────────────────────┐
│ Metadata Enrichment          │
│ ChunkMetadataEnricher        │
└───────────────┬──────────────┘
                │
                ▼
┌──────────────────────────────┐
│ Chunk Filtering              │
│ ChunkFilter                  │
└───────────────┬──────────────┘
                │
                ▼
┌──────────────────────────────┐
│ Neuron Embeddings Provider   │
│ Ollama bge-m3 / nomic        │
└───────────────┬──────────────┘
                │
                ▼
┌──────────────────────────────┐
│ PostgreSQL + pgvector        │
│ document_chunks.embedding    │
└──────────────────────────────┘
```

Запрос пользователя:

```text
User question
→ normalize question
→ embedding через ту же embedding-модель
→ pgvector similarity search
→ top-k chunks
→ context builder
→ Neuron RAG Agent
→ OpenRouter LLM
→ answer + sources
```

---

## 2. Выбранные модели

### 2.1 Embeddings

Для старта рекомендую локальный вариант через Ollama:

```text
Модель: bge-m3
Провайдер: Ollama
Размерность: 1024
PostgreSQL field: vector(1024)
```

Почему `bge-m3`:

- хорошо подходит для RAG;
- поддерживает русский и английский;
- локальная модель, не нужно отправлять документы в облако;
- удобна через Ollama.

Команда:

```bash
ollama pull bge-m3
```

Альтернативы:

```text
nomic-embed-text
Размерность обычно: 768
PostgreSQL field: vector(768)

mxbai-embed-large
Размерность обычно: 1024
PostgreSQL field: vector(1024)
```

Главное правило:

```text
Одна и та же embedding-модель должна использоваться:
1. для индексации документов;
2. для embedding пользовательских вопросов.
```

Нельзя индексировать документы через `bge-m3`, а вопросы искать через другую embedding-модель.

---

### 2.2 LLM через OpenRouter

Для LLM используем OpenRouter, потому что он даёт OpenAI-compatible API и коллекцию бесплатных моделей.

OpenRouter API base URL:

```env
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
```

Рекомендуемые бесплатные модели для старта:

```env
OPENROUTER_MODEL=openrouter/free
```

Или конкретные free-модели из коллекции OpenRouter:

```text
deepseek/deepseek-chat-v3-0324:free
deepseek/deepseek-r1:free
qwen/qwen3-coder:free
meta-llama/llama-3.3-70b-instruct:free
google/gemini-2.0-flash-exp:free
```

Важно: список бесплатных моделей на OpenRouter может меняться. Для проекта лучше хранить модель в `.env`, чтобы менять её без кода.

Страница бесплатных моделей: <https://openrouter.ai/collections/free-models>

---

## 3. Установка зависимостей Laravel

```bash
composer require neuron-core/neuron-ai
composer require neuron-core/neuron-laravel
composer require phpoffice/phpword
composer require league/commonmark
```

Опционально:

```bash
composer require smalot/pdfparser
```

PDF сейчас не нужен, но если потом захочешь добавлять PDF, понадобится отдельный extractor.

Публикация конфига Neuron Laravel:

```bash
php artisan vendor:publish --tag=neuron-config
```

Если будешь использовать встроенную историю чата Neuron:

```bash
php artisan vendor:publish --tag=neuron-migrations
php artisan migrate --path=/database/migrations/neuron
```

---

## 4. Настройки `.env`

```env
APP_NAME="Laravel RAG"
APP_ENV=local
APP_DEBUG=true

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=rag_app
DB_USERNAME=postgres
DB_PASSWORD=password

QUEUE_CONNECTION=database

RAG_EMBEDDING_PROVIDER=ollama
RAG_EMBEDDING_MODEL=bge-m3
RAG_EMBEDDING_DIMENSIONS=1024
OLLAMA_BASE_URL=http://127.0.0.1:11434

RAG_LLM_PROVIDER=openrouter
OPENROUTER_API_KEY=sk-or-your-key
OPENROUTER_BASE_URL=https://openrouter.ai/api/v1
OPENROUTER_MODEL=openrouter/free
OPENROUTER_SITE_URL=http://localhost
OPENROUTER_APP_NAME="Laravel RAG Pet Project"

RAG_TOP_K=8
RAG_MAX_CONTEXT_CHARS=16000
RAG_CHUNK_SIZE_CHARS=3200
RAG_CHUNK_OVERLAP_CHARS=500
```

Для OpenRouter желательно передавать заголовки:

```text
Authorization: Bearer {OPENROUTER_API_KEY}
HTTP-Referer: {OPENROUTER_SITE_URL}
X-Title: {OPENROUTER_APP_NAME}
```

---

## 5. Настройка PostgreSQL локально
Для упрощения установки и избежания проблем с локальной установкой расширения pgvector, используем готовый Docker-образ с уже встроенной поддержкой векторов.

📦 5.1 docker-compose.yml

Создай файл docker-compose.yml в корне проекта:

version: "3.9"

services:
  postgres:
    image: pgvector/pgvector:pg16
    container_name: rag-postgres
    restart: unless-stopped
    environment:
      POSTGRES_DB: rag_db
      POSTGRES_USER: rag_user
      POSTGRES_PASSWORD: rag_password
    ports:
      - "5433:5432"
    volumes:
      - rag_pg_data:/var/lib/postgresql/data

volumes:
  rag_pg_data:
🚀 5.2 Запуск контейнера
docker compose up -d

Проверка:

docker ps
🔗 5.3 Настройка Laravel (.env)
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=rag_db
DB_USERNAME=rag_user
DB_PASSWORD=rag_password
🧠 5.4 Включение pgvector

Подключись к контейнеру:

docker exec -it rag-postgres psql -U rag_user -d rag_db

Выполни:

CREATE EXTENSION IF NOT EXISTS vector;

Проверка:

SELECT extname FROM pg_extension WHERE extname = 'vector';

## 6. Миграции Laravel

### 6.1 `documents`

```php
Schema::create('documents', function (Blueprint $table) {
    $table->id();

    $table->string('title');
    $table->string('original_filename');
    $table->string('mime_type')->nullable();
    $table->string('extension', 20);

    $table->string('source_type')->default('upload');
    $table->string('source_path')->nullable();

    $table->string('status')->default('uploaded');
    $table->string('content_hash', 64)->nullable();

    $table->text('error_message')->nullable();

    $table->timestamps();
});
```

Статусы:

```text
uploaded
processing
indexed
failed
outdated
deleted
```

---

### 6.2 `document_versions`

```php
Schema::create('document_versions', function (Blueprint $table) {
    $table->id();

    $table->foreignId('document_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->string('version_hash', 64);

    $table->longText('raw_text')->nullable();
    $table->longText('normalized_text')->nullable();

    $table->jsonb('metadata')->nullable();

    $table->string('status')->default('pending');

    $table->timestamps();

    $table->unique(['document_id', 'version_hash']);
});
```

---

### 6.3 `document_chunks`

```php
Schema::create('document_chunks', function (Blueprint $table) {
    $table->id();

    $table->foreignId('document_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->foreignId('document_version_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->integer('chunk_index');

    $table->longText('content');
    $table->string('content_hash', 64);

    $table->integer('char_count')->nullable();
    $table->integer('token_estimate')->nullable();

    $table->string('heading')->nullable();
    $table->string('section_path')->nullable();
    $table->integer('page_number')->nullable();

    $table->jsonb('metadata')->nullable();

    $table->boolean('is_active')->default(true);

    $table->timestamps();

    $table->index(['document_id', 'is_active']);
    $table->index(['document_version_id', 'is_active']);
    $table->index('content_hash');
});
```

После создания таблицы добавляем vector-поле через raw SQL:

```php
DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(1024)');
```

Индекс:

```php
DB::statement('
    CREATE INDEX document_chunks_embedding_hnsw_idx
    ON document_chunks
    USING hnsw (embedding vector_cosine_ops)
');
```

Если используешь `nomic-embed-text`, замени на:

```php
DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(768)');
```

---

### 6.4 `rag_queries`

```php
Schema::create('rag_queries', function (Blueprint $table) {
    $table->id();

    $table->foreignId('user_id')->nullable();

    $table->text('question');
    $table->longText('answer')->nullable();

    $table->string('llm_provider')->nullable();
    $table->string('llm_model')->nullable();
    $table->string('embedding_model')->nullable();

    $table->integer('top_k')->nullable();

    $table->jsonb('metadata')->nullable();

    $table->timestamps();
});
```

---

### 6.5 `rag_query_chunks`

```php
Schema::create('rag_query_chunks', function (Blueprint $table) {
    $table->id();

    $table->foreignId('rag_query_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->foreignId('document_chunk_id')
        ->constrained()
        ->cascadeOnDelete();

    $table->float('distance')->nullable();
    $table->integer('rank')->nullable();

    $table->timestamps();
});
```

---

## 7. Структура проекта

```text
app/
  Domain/
    Documents/
      DTO/
        ExtractedDocumentText.php
        PreparedChunk.php

      Models/
        Document.php
        DocumentVersion.php
        DocumentChunk.php

      Services/
        DocumentImportService.php
        TextExtraction/
          DocumentTextExtractor.php
          MarkdownTextExtractor.php
          DocxTextExtractor.php
          TextExtractorFactory.php

        TextProcessing/
          RagTextSanitizer.php
          MarkdownAwareChunker.php
          RecursiveTextChunker.php
          ChunkMetadataEnricher.php
          ChunkFilter.php

        Indexing/
          DocumentIndexingService.php
          DocumentChangeDetector.php

      Jobs/
        ProcessDocumentJob.php
        ReindexDocumentJob.php

    Rag/
      DTO/
        RetrievedChunk.php
        RagAnswer.php

      Services/
        Embeddings/
          EmbeddingProvider.php
          OllamaEmbeddingProvider.php

        VectorStore/
          PgVectorStore.php

        Context/
          RagContextBuilder.php
          RagPromptBuilder.php

        RagAnswerService.php
        OpenRouterChatClient.php

app/
  Neuron/
    DocumentRAG.php
```

---

## 8. DTO

### 8.1 `ExtractedDocumentText`

```php
<?php

namespace App\Domain\Documents\DTO;

final class ExtractedDocumentText
{
    public function __construct(
        public readonly string $text,
        public readonly array $metadata = [],
    ) {
    }
}
```

---

### 8.2 `PreparedChunk`

```php
<?php

namespace App\Domain\Documents\DTO;

final class PreparedChunk
{
    public function __construct(
        public readonly string $content,
        public readonly int $chunkIndex,
        public readonly array $metadata = [],
        public readonly ?string $heading = null,
        public readonly ?string $sectionPath = null,
        public readonly ?int $pageNumber = null,
    ) {
    }

    public function contentHash(): string
    {
        return hash('sha256', mb_strtolower(trim($this->content)));
    }

    public function charCount(): int
    {
        return mb_strlen($this->content);
    }

    public function tokenEstimate(): int
    {
        return (int) ceil(mb_strlen($this->content) / 4);
    }
}
```

---

## 9. Извлечение текста из `.md`

Markdown обрабатывать проще, чем Word.

Задачи:

1. Прочитать файл.
2. Сохранить markdown-структуру.
3. Не удалять заголовки.
4. Не ломать таблицы.
5. Убрать HTML-комментарии и лишний мусор.

### 9.1 Интерфейс extractor

```php
<?php

namespace App\Domain\Documents\Services\TextExtraction;

use App\Domain\Documents\DTO\ExtractedDocumentText;

interface DocumentTextExtractor
{
    public function supports(string $extension, ?string $mimeType = null): bool;

    public function extract(string $path): ExtractedDocumentText;
}
```

---

### 9.2 `MarkdownTextExtractor`

```php
<?php

namespace App\Domain\Documents\Services\TextExtraction;

use App\Domain\Documents\DTO\ExtractedDocumentText;

final class MarkdownTextExtractor implements DocumentTextExtractor
{
    public function supports(string $extension, ?string $mimeType = null): bool
    {
        return in_array(mb_strtolower($extension), ['md', 'markdown'], true);
    }

    public function extract(string $path): ExtractedDocumentText
    {
        $text = file_get_contents($path);

        if ($text === false) {
            throw new \RuntimeException("Cannot read markdown file: {$path}");
        }

        $text = preg_replace('/<!--.*?-->/s', '', $text);

        return new ExtractedDocumentText(
            text: $text,
            metadata: [
                'format' => 'markdown',
            ],
        );
    }
}
```

---

## 10. Извлечение текста из `.docx`

Для `.docx` используем `phpoffice/phpword`.

Что важно:

1. Word-документ состоит из секций.
2. В секциях есть элементы: текст, списки, таблицы.
3. Таблицы лучше преобразовывать в markdown-таблицы или хотя бы в читаемые строки.
4. Заголовки можно определять частично по style name, но для пет-проекта можно начать с plain text.

### 10.1 `DocxTextExtractor`

```php
<?php

namespace App\Domain\Documents\Services\TextExtraction;

use App\Domain\Documents\DTO\ExtractedDocumentText;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\ListItem;

final class DocxTextExtractor implements DocumentTextExtractor
{
    public function supports(string $extension, ?string $mimeType = null): bool
    {
        return mb_strtolower($extension) === 'docx';
    }

    public function extract(string $path): ExtractedDocumentText
    {
        $phpWord = IOFactory::load($path);

        $blocks = [];

        foreach ($phpWord->getSections() as $sectionIndex => $section) {
            foreach ($section->getElements() as $element) {
                $text = $this->extractElementText($element);

                if ($text !== '') {
                    $blocks[] = $text;
                }
            }
        }

        return new ExtractedDocumentText(
            text: implode("\n\n", $blocks),
            metadata: [
                'format' => 'docx',
            ],
        );
    }

    private function extractElementText(object $element): string
    {
        if ($element instanceof Text) {
            return trim($element->getText());
        }

        if ($element instanceof TextRun) {
            $parts = [];

            foreach ($element->getElements() as $child) {
                if ($child instanceof Text) {
                    $parts[] = $child->getText();
                }
            }

            return trim(implode('', $parts));
        }

        if ($element instanceof ListItem) {
            return '- ' . trim($element->getTextObject()->getText());
        }

        if ($element instanceof Table) {
            return $this->extractTable($element);
        }

        return '';
    }

    private function extractTable(Table $table): string
    {
        $rows = [];

        foreach ($table->getRows() as $row) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                $cellParts = [];

                foreach ($cell->getElements() as $cellElement) {
                    $cellText = $this->extractElementText($cellElement);

                    if ($cellText !== '') {
                        $cellParts[] = $cellText;
                    }
                }

                $cells[] = trim(implode(' ', $cellParts));
            }

            if (! empty($cells)) {
                $rows[] = '| ' . implode(' | ', $cells) . ' |';
            }
        }

        if (count($rows) > 1) {
            $columnCount = substr_count($rows[0], '|') - 1;
            $separator = '| ' . implode(' | ', array_fill(0, $columnCount, '---')) . ' |';

            array_splice($rows, 1, 0, [$separator]);
        }

        return implode("\n", $rows);
    }
}
```

---

### 10.2 `TextExtractorFactory`

```php
<?php

namespace App\Domain\Documents\Services\TextExtraction;

final class TextExtractorFactory
{
    /**
     * @param DocumentTextExtractor[] $extractors
     */
    public function __construct(
        private readonly array $extractors,
    ) {
    }

    public function for(string $extension, ?string $mimeType = null): DocumentTextExtractor
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($extension, $mimeType)) {
                return $extractor;
            }
        }

        throw new \InvalidArgumentException("Unsupported document type: {$extension}");
    }
}
```

В `AppServiceProvider`:

```php
use App\Domain\Documents\Services\TextExtraction\TextExtractorFactory;
use App\Domain\Documents\Services\TextExtraction\MarkdownTextExtractor;
use App\Domain\Documents\Services\TextExtraction\DocxTextExtractor;

$this->app->singleton(TextExtractorFactory::class, function () {
    return new TextExtractorFactory([
        new MarkdownTextExtractor(),
        new DocxTextExtractor(),
    ]);
});
```

---

## 11. Нормализация текста

```php
<?php

namespace App\Domain\Documents\Services\TextProcessing;

final class RagTextSanitizer
{
    public function sanitize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Убираем невидимые символы, кроме перевода строки и табуляции
        $text = preg_replace('/[^\P{C}\n\t]+/u', '', $text);

        // Схлопываем пробелы
        $text = preg_replace('/[ \t]+/u', ' ', $text);

        // Слишком много пустых строк
        $text = preg_replace("/\n{4,}/u", "\n\n\n", $text);

        // Убираем типовые Word-маркеры
        $text = preg_replace('/Страница\s+\d+\s+из\s+\d+/iu', '', $text);

        return trim($text);
    }
}
```

---

## 12. Чанкинг

### 12.1 Почему не резать просто по символам

Плохо:

```text
chunk = каждые 3000 символов
```

Проблема:

- можно разорвать таблицу;
- можно разорвать определение;
- можно потерять заголовок;
- можно отделить объяснение от важного условия.

Лучше:

1. Для Markdown сначала учитывать заголовки.
2. Для DOCX после извлечения текста использовать абзацы.
3. Большие блоки резать рекурсивно.
4. Добавлять overlap.

---

### 12.2 `RecursiveTextChunker`

```php
<?php

namespace App\Domain\Documents\Services\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;

final class RecursiveTextChunker
{
    public function __construct(
        private readonly int $chunkSizeChars = 3200,
        private readonly int $overlapChars = 500,
    ) {
    }

    /**
     * @return PreparedChunk[]
     */
    public function split(string $text, array $baseMetadata = []): array
    {
        $paragraphs = preg_split("/\n{2,}/u", $text) ?: [];

        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($buffer . "\n\n" . $paragraph) <= $this->chunkSizeChars) {
                $buffer = trim($buffer . "\n\n" . $paragraph);
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
            }

            if (mb_strlen($paragraph) > $this->chunkSizeChars) {
                foreach ($this->splitLargeText($paragraph) as $part) {
                    $chunks[] = $part;
                }

                $buffer = '';
            } else {
                $buffer = $paragraph;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        $chunks = $this->applyOverlap($chunks);

        return array_map(
            fn (string $content, int $index) => new PreparedChunk(
                content: $content,
                chunkIndex: $index,
                metadata: $baseMetadata,
            ),
            $chunks,
            array_keys($chunks)
        );
    }

    /**
     * @return string[]
     */
    private function splitLargeText(string $text): array
    {
        $parts = [];
        $length = mb_strlen($text);
        $start = 0;

        while ($start < $length) {
            $parts[] = mb_substr($text, $start, $this->chunkSizeChars);
            $start += $this->chunkSizeChars;
        }

        return $parts;
    }

    /**
     * @param string[] $chunks
     * @return string[]
     */
    private function applyOverlap(array $chunks): array
    {
        $result = [];

        foreach ($chunks as $index => $chunk) {
            if ($index === 0) {
                $result[] = $chunk;
                continue;
            }

            $previous = $chunks[$index - 1];
            $overlap = mb_substr($previous, max(0, mb_strlen($previous) - $this->overlapChars));

            $result[] = trim($overlap . "\n\n" . $chunk);
        }

        return $result;
    }
}
```

---

### 12.3 Markdown-aware chunking

Для `.md` полезно сохранять текущий заголовок.

```php
<?php

namespace App\Domain\Documents\Services\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;

final class MarkdownAwareChunker
{
    public function __construct(
        private readonly RecursiveTextChunker $recursiveChunker,
    ) {
    }

    /**
     * @return PreparedChunk[]
     */
    public function split(string $markdown, array $baseMetadata = []): array
    {
        $lines = preg_split("/\n/u", $markdown) ?: [];

        $sections = [];
        $currentHeadingPath = [];
        $currentContent = [];

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $matches)) {
                if (! empty($currentContent)) {
                    $sections[] = [
                        'section_path' => implode(' / ', $currentHeadingPath),
                        'content' => implode("\n", $currentContent),
                    ];

                    $currentContent = [];
                }

                $level = mb_strlen($matches[1]);
                $title = trim($matches[2]);

                $currentHeadingPath = array_slice($currentHeadingPath, 0, $level - 1);
                $currentHeadingPath[$level - 1] = $title;

                $currentContent[] = $line;
                continue;
            }

            $currentContent[] = $line;
        }

        if (! empty($currentContent)) {
            $sections[] = [
                'section_path' => implode(' / ', $currentHeadingPath),
                'content' => implode("\n", $currentContent),
            ];
        }

        $prepared = [];
        $globalIndex = 0;

        foreach ($sections as $section) {
            $chunks = $this->recursiveChunker->split(
                text: $section['content'],
                baseMetadata: [
                    ...$baseMetadata,
                    'section_path' => $section['section_path'],
                ],
            );

            foreach ($chunks as $chunk) {
                $prepared[] = new PreparedChunk(
                    content: $chunk->content,
                    chunkIndex: $globalIndex++,
                    metadata: [
                        ...$chunk->metadata,
                        'section_path' => $section['section_path'],
                    ],
                    heading: $section['section_path'],
                    sectionPath: $section['section_path'],
                );
            }
        }

        return $prepared;
    }
}
```

---

## 13. Метаданные чанков

Метаданные добавляются до embedding и до сохранения в vector store.

### 13.1 `ChunkMetadataEnricher`

```php
<?php

namespace App\Domain\Documents\Services\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;

final class ChunkMetadataEnricher
{
    /**
     * @param PreparedChunk[] $chunks
     * @return PreparedChunk[]
     */
    public function enrich(array $chunks, Document $document, DocumentVersion $version): array
    {
        return array_map(function (PreparedChunk $chunk) use ($document, $version) {
            $metadata = [
                ...$chunk->metadata,
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'document_title' => $document->title,
                'original_filename' => $document->original_filename,
                'extension' => $document->extension,
                'source_type' => $document->source_type,
                'source_path' => $document->source_path,
                'chunk_index' => $chunk->chunkIndex,
                'content_hash' => $chunk->contentHash(),
                'char_count' => $chunk->charCount(),
                'token_estimate' => $chunk->tokenEstimate(),
            ];

            return new PreparedChunk(
                content: $chunk->content,
                chunkIndex: $chunk->chunkIndex,
                metadata: $metadata,
                heading: $chunk->heading,
                sectionPath: $chunk->sectionPath,
                pageNumber: $chunk->pageNumber,
            );
        }, $chunks);
    }
}
```

---

## 14. Фильтрация перед embedding

Фильтровать нужно до вызова embedding-модели, чтобы:

- не тратить время;
- не забивать индекс мусором;
- не ухудшать поиск;
- не отправлять в модель ненужные блоки.

### 14.1 `ChunkFilter`

```php
<?php

namespace App\Domain\Documents\Services\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;

final class ChunkFilter
{
    /**
     * @param PreparedChunk[] $chunks
     * @return PreparedChunk[]
     */
    public function filter(array $chunks): array
    {
        $result = [];
        $seen = [];

        foreach ($chunks as $chunk) {
            $text = trim($chunk->content);

            if ($text === '') {
                continue;
            }

            if (mb_strlen($text) < 120) {
                continue;
            }

            if ($this->isMostlyNumbersOrSymbols($text)) {
                continue;
            }

            if ($this->isUnwantedSection($text, $chunk->sectionPath)) {
                continue;
            }

            $hash = $chunk->contentHash();

            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $result[] = $chunk;
        }

        return array_values($result);
    }

    private function isMostlyNumbersOrSymbols(string $text): bool
    {
        $letters = preg_match_all('/[\p{L}]/u', $text);
        $length = max(1, mb_strlen($text));

        return ($letters / $length) < 0.25;
    }

    private function isUnwantedSection(string $text, ?string $sectionPath): bool
    {
        $haystack = mb_strtolower(($sectionPath ?? '') . "\n" . mb_substr($text, 0, 300));

        $badMarkers = [
            'список литературы',
            'references',
            'bibliography',
            'оглавление',
            'содержание',
        ];

        foreach ($badMarkers as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }
}
```

Важно: список литературы иногда нужен. Например, если пользователь будет спрашивать “какие источники использованы?”. Поэтому это лучше сделать настройкой.

---

## 15. Embeddings provider

Neuron имеет свои embeddings providers. Для Ollama можно использовать готовый `OllamaEmbeddingsProvider`.

Но в проекте полезно сделать свой application-level wrapper, чтобы изоляция от Neuron была лучше.

### 15.1 Интерфейс

```php
<?php

namespace App\Domain\Rag\Services\Embeddings;

interface EmbeddingProvider
{
    /**
     * @return float[]
     */
    public function embed(string $text): array;
}
```

---

### 15.2 Прямой Ollama provider через HTTP

Этот класс можно использовать, если хочешь полностью контролировать запросы.

```php
<?php

namespace App\Domain\Rag\Services\Embeddings;

use Illuminate\Support\Facades\Http;

final class OllamaEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $model,
    ) {
    }

    public function embed(string $text): array
    {
        $response = Http::timeout(120)
            ->post(rtrim($this->baseUrl, '/') . '/api/embed', [
                'model' => $this->model,
                'input' => $text,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Ollama embedding failed: ' . $response->body());
        }

        $json = $response->json();

        if (isset($json['embeddings'][0])) {
            return array_map('floatval', $json['embeddings'][0]);
        }

        if (isset($json['embedding'])) {
            return array_map('floatval', $json['embedding']);
        }

        throw new \RuntimeException('Unexpected Ollama embedding response: ' . json_encode($json));
    }
}
```

Регистрация:

```php
use App\Domain\Rag\Services\Embeddings\EmbeddingProvider;
use App\Domain\Rag\Services\Embeddings\OllamaEmbeddingProvider;

$this->app->singleton(EmbeddingProvider::class, function () {
    return new OllamaEmbeddingProvider(
        baseUrl: config('rag.ollama.base_url'),
        model: config('rag.embedding.model'),
    );
});
```

---

## 16. PgVectorStore

Neuron из коробки имеет memory/file vector stores. Для PostgreSQL лучше сделать свой `PgVectorStore`.

Этот класс:

1. сохраняет чанк и embedding;
2. ищет похожие чанки;
3. фильтрует по документам/metadata;
4. отдаёт текст и источники.

### 16.1 DTO `RetrievedChunk`

```php
<?php

namespace App\Domain\Rag\DTO;

final class RetrievedChunk
{
    public function __construct(
        public readonly int $id,
        public readonly int $documentId,
        public readonly string $content,
        public readonly array $metadata,
        public readonly float $distance,
        public readonly int $rank,
    ) {
    }
}
```

---

### 16.2 `PgVectorStore`

```php
<?php

namespace App\Domain\Rag\Services\VectorStore;

use App\Domain\Documents\DTO\PreparedChunk;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentChunk;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Rag\DTO\RetrievedChunk;
use Illuminate\Support\Facades\DB;

final class PgVectorStore
{
    public function addChunk(
        Document $document,
        DocumentVersion $version,
        PreparedChunk $chunk,
        array $embedding,
    ): DocumentChunk {
        $vector = $this->toPgVector($embedding);

        return DB::transaction(function () use ($document, $version, $chunk, $vector) {
            DB::statement(
                '
                INSERT INTO document_chunks (
                    document_id,
                    document_version_id,
                    chunk_index,
                    content,
                    content_hash,
                    char_count,
                    token_estimate,
                    heading,
                    section_path,
                    page_number,
                    metadata,
                    is_active,
                    embedding,
                    created_at,
                    updated_at
                )
                VALUES (
                    :document_id,
                    :document_version_id,
                    :chunk_index,
                    :content,
                    :content_hash,
                    :char_count,
                    :token_estimate,
                    :heading,
                    :section_path,
                    :page_number,
                    :metadata,
                    true,
                    :embedding::vector,
                    NOW(),
                    NOW()
                )
                ',
                [
                    'document_id' => $document->id,
                    'document_version_id' => $version->id,
                    'chunk_index' => $chunk->chunkIndex,
                    'content' => $chunk->content,
                    'content_hash' => $chunk->contentHash(),
                    'char_count' => $chunk->charCount(),
                    'token_estimate' => $chunk->tokenEstimate(),
                    'heading' => $chunk->heading,
                    'section_path' => $chunk->sectionPath,
                    'page_number' => $chunk->pageNumber,
                    'metadata' => json_encode($chunk->metadata, JSON_UNESCAPED_UNICODE),
                    'embedding' => $vector,
                ]
            );

            return DocumentChunk::query()
                ->where('document_id', $document->id)
                ->where('document_version_id', $version->id)
                ->where('chunk_index', $chunk->chunkIndex)
                ->firstOrFail();
        });
    }

    /**
     * @return RetrievedChunk[]
     */
    public function similaritySearch(
        array $queryEmbedding,
        int $limit = 8,
        array $filters = [],
    ): array {
        $vector = $this->toPgVector($queryEmbedding);

        $where = ['is_active = true'];
        $bindings = [
            'query_embedding_1' => $vector,
            'query_embedding_2' => $vector,
            'limit' => $limit,
        ];

        if (isset($filters['document_id'])) {
            $where[] = 'document_id = :document_id';
            $bindings['document_id'] = $filters['document_id'];
        }

        if (isset($filters['extension'])) {
            $where[] = "metadata->>'extension' = :extension";
            $bindings['extension'] = $filters['extension'];
        }

        $sql = '
            SELECT
                id,
                document_id,
                content,
                metadata,
                embedding <=> :query_embedding_1::vector AS distance
            FROM document_chunks
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY embedding <=> :query_embedding_2::vector
            LIMIT :limit
        ';

        $rows = DB::select($sql, $bindings);

        return array_map(function ($row, int $index) {
            return new RetrievedChunk(
                id: (int) $row->id,
                documentId: (int) $row->document_id,
                content: $row->content,
                metadata: json_decode($row->metadata ?? '{}', true) ?: [],
                distance: (float) $row->distance,
                rank: $index + 1,
            );
        }, $rows, array_keys($rows));
    }

    public function deactivateDocumentChunks(int $documentId): void
    {
        DB::table('document_chunks')
            ->where('document_id', $documentId)
            ->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param float[] $embedding
     */
    private function toPgVector(array $embedding): string
    {
        return '[' . implode(',', array_map(
            fn ($value) => sprintf('%.10F', (float) $value),
            $embedding
        )) . ']';
    }
}
```

---

## 17. Индексация документа

### 17.1 `DocumentImportService`

```php
<?php

namespace App\Domain\Documents\Services;

use App\Domain\Documents\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class DocumentImportService
{
    public function import(UploadedFile $file): Document
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, ['md', 'markdown', 'docx'], true)) {
            throw new \InvalidArgumentException('Only .md and .docx files are supported.');
        }

        $path = $file->store('rag/documents');

        return Document::query()->create([
            'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'extension' => $extension,
            'source_type' => 'upload',
            'source_path' => $path,
            'status' => 'uploaded',
        ]);
    }
}
```

---

### 17.2 `DocumentIndexingService`

```php
<?php

namespace App\Domain\Documents\Services\Indexing;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Models\DocumentVersion;
use App\Domain\Documents\Services\TextExtraction\TextExtractorFactory;
use App\Domain\Documents\Services\TextProcessing\ChunkFilter;
use App\Domain\Documents\Services\TextProcessing\ChunkMetadataEnricher;
use App\Domain\Documents\Services\TextProcessing\MarkdownAwareChunker;
use App\Domain\Documents\Services\TextProcessing\RagTextSanitizer;
use App\Domain\Documents\Services\TextProcessing\RecursiveTextChunker;
use App\Domain\Rag\Services\Embeddings\EmbeddingProvider;
use App\Domain\Rag\Services\VectorStore\PgVectorStore;
use Illuminate\Support\Facades\Storage;

final class DocumentIndexingService
{
    public function __construct(
        private readonly TextExtractorFactory $extractorFactory,
        private readonly RagTextSanitizer $sanitizer,
        private readonly RecursiveTextChunker $recursiveChunker,
        private readonly MarkdownAwareChunker $markdownChunker,
        private readonly ChunkMetadataEnricher $metadataEnricher,
        private readonly ChunkFilter $chunkFilter,
        private readonly EmbeddingProvider $embeddingProvider,
        private readonly PgVectorStore $vectorStore,
    ) {
    }

    public function index(Document $document): void
    {
        $document->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            $absolutePath = Storage::path($document->source_path);

            $extractor = $this->extractorFactory->for(
                extension: $document->extension,
                mimeType: $document->mime_type,
            );

            $extracted = $extractor->extract($absolutePath);
            $normalizedText = $this->sanitizer->sanitize($extracted->text);

            $versionHash = hash('sha256', $normalizedText);

            $existingVersion = DocumentVersion::query()
                ->where('document_id', $document->id)
                ->where('version_hash', $versionHash)
                ->first();

            if ($existingVersion !== null) {
                $document->update([
                    'status' => 'indexed',
                    'content_hash' => $versionHash,
                ]);

                return;
            }

            $version = DocumentVersion::query()->create([
                'document_id' => $document->id,
                'version_hash' => $versionHash,
                'raw_text' => $extracted->text,
                'normalized_text' => $normalizedText,
                'metadata' => [
                    ...$extracted->metadata,
                    'embedding_model' => config('rag.embedding.model'),
                ],
                'status' => 'processing',
            ]);

            $baseMetadata = [
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'document_title' => $document->title,
                'extension' => $document->extension,
            ];

            if (in_array($document->extension, ['md', 'markdown'], true)) {
                $chunks = $this->markdownChunker->split($normalizedText, $baseMetadata);
            } else {
                $chunks = $this->recursiveChunker->split($normalizedText, $baseMetadata);
            }

            $chunks = $this->metadataEnricher->enrich($chunks, $document, $version);
            $chunks = $this->chunkFilter->filter($chunks);

            $this->vectorStore->deactivateDocumentChunks($document->id);

            foreach ($chunks as $chunk) {
                $embeddingText = $this->buildEmbeddingText($chunk->content, $chunk->metadata);
                $embedding = $this->embeddingProvider->embed($embeddingText);

                $this->vectorStore->addChunk(
                    document: $document,
                    version: $version,
                    chunk: $chunk,
                    embedding: $embedding,
                );
            }

            $version->update(['status' => 'indexed']);

            $document->update([
                'status' => 'indexed',
                'content_hash' => $versionHash,
            ]);
        } catch (\Throwable $e) {
            $document->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function buildEmbeddingText(string $content, array $metadata): string
    {
        $prefix = [];

        if (! empty($metadata['document_title'])) {
            $prefix[] = 'Документ: ' . $metadata['document_title'];
        }

        if (! empty($metadata['section_path'])) {
            $prefix[] = 'Раздел: ' . $metadata['section_path'];
        }

        if ($prefix === []) {
            return $content;
        }

        return implode("\n", $prefix) . "\n\n" . $content;
    }
}
```

Важно: metadata сама по себе не превращается в embedding. Поэтому полезные метаданные, например название документа и раздел, можно добавить в `embeddingText`.

---

### 17.3 `ProcessDocumentJob`

```php
<?php

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Services\Indexing\DocumentIndexingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(
        public readonly int $documentId,
    ) {
    }

    public function handle(DocumentIndexingService $indexingService): void
    {
        $document = Document::query()->findOrFail($this->documentId);

        $indexingService->index($document);
    }
}
```

---

## 18. OpenRouter LLM client

Neuron может работать с OpenAI-compatible providers через `OpenAILike`. Но для полного контроля можно сделать обычный Laravel HTTP-клиент.

```php
<?php

namespace App\Domain\Rag\Services;

use Illuminate\Support\Facades\Http;

final class OpenRouterChatClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly ?string $siteUrl = null,
        private readonly ?string $appName = null,
    ) {
    }

    /**
     * @param array<int, array{role: string, content: string}> $messages
     */
    public function chat(array $messages): string
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ];

        if ($this->siteUrl) {
            $headers['HTTP-Referer'] = $this->siteUrl;
        }

        if ($this->appName) {
            $headers['X-Title'] = $this->appName;
        }

        $response = Http::withHeaders($headers)
            ->timeout(120)
            ->post(rtrim($this->baseUrl, '/') . '/chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.2,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('OpenRouter request failed: ' . $response->body());
        }

        $json = $response->json();

        return $json['choices'][0]['message']['content']
            ?? throw new \RuntimeException('Unexpected OpenRouter response: ' . json_encode($json));
    }
}
```

Регистрация:

```php
use App\Domain\Rag\Services\OpenRouterChatClient;

$this->app->singleton(OpenRouterChatClient::class, function () {
    return new OpenRouterChatClient(
        apiKey: config('rag.openrouter.api_key'),
        baseUrl: config('rag.openrouter.base_url'),
        model: config('rag.openrouter.model'),
        siteUrl: config('rag.openrouter.site_url'),
        appName: config('rag.openrouter.app_name'),
    );
});
```

---

## 19. Context builder

После vector search мы получили найденные чанки. Их надо превратить в контекст.

```php
<?php

namespace App\Domain\Rag\Services\Context;

use App\Domain\Rag\DTO\RetrievedChunk;

final class RagContextBuilder
{
    /**
     * @param RetrievedChunk[] $chunks
     */
    public function build(array $chunks, int $maxChars = 16000): string
    {
        $context = '';

        foreach ($chunks as $chunk) {
            $title = $chunk->metadata['document_title'] ?? ('Document #' . $chunk->documentId);
            $section = $chunk->metadata['section_path'] ?? null;

            $block = "[Источник {$chunk->rank}]\n";
            $block .= "Документ: {$title}\n";

            if ($section) {
                $block .= "Раздел: {$section}\n";
            }

            $block .= "Chunk ID: {$chunk->id}\n";
            $block .= "Distance: {$chunk->distance}\n";
            $block .= "Текст:\n{$chunk->content}\n\n";

            if (mb_strlen($context . $block) > $maxChars) {
                break;
            }

            $context .= $block;
        }

        return trim($context);
    }
}
```

---

## 20. Prompt builder

```php
<?php

namespace App\Domain\Rag\Services\Context;

final class RagPromptBuilder
{
    public function buildMessages(string $question, string $context): array
    {
        return [
            [
                'role' => 'system',
                'content' => implode("\n", [
                    'Ты — помощник RAG-системы.',
                    'Отвечай только на основе предоставленного контекста.',
                    'Если в контексте нет ответа, скажи: "В предоставленных документах нет достаточной информации".',
                    'Не выдумывай факты.',
                    'Отвечай на русском языке.',
                    'В конце ответа кратко укажи использованные источники.',
                ]),
            ],
            [
                'role' => 'user',
                'content' => "Контекст:\n{$context}\n\nВопрос пользователя:\n{$question}",
            ],
        ];
    }
}
```

---

## 21. RAG answer service

```php
<?php

namespace App\Domain\Rag\Services;

use App\Domain\Rag\DTO\RagAnswer;
use App\Domain\Rag\Services\Context\RagContextBuilder;
use App\Domain\Rag\Services\Context\RagPromptBuilder;
use App\Domain\Rag\Services\Embeddings\EmbeddingProvider;
use App\Domain\Rag\Services\VectorStore\PgVectorStore;
use Illuminate\Support\Facades\DB;

final class RagAnswerService
{
    public function __construct(
        private readonly EmbeddingProvider $embeddingProvider,
        private readonly PgVectorStore $vectorStore,
        private readonly RagContextBuilder $contextBuilder,
        private readonly RagPromptBuilder $promptBuilder,
        private readonly OpenRouterChatClient $chatClient,
    ) {
    }

    public function answer(string $question, array $filters = []): RagAnswer
    {
        $queryEmbedding = $this->embeddingProvider->embed($question);

        $chunks = $this->vectorStore->similaritySearch(
            queryEmbedding: $queryEmbedding,
            limit: (int) config('rag.top_k', 8),
            filters: $filters,
        );

        $context = $this->contextBuilder->build(
            chunks: $chunks,
            maxChars: (int) config('rag.max_context_chars', 16000),
        );

        $messages = $this->promptBuilder->buildMessages($question, $context);

        $answer = $this->chatClient->chat($messages);

        $queryId = DB::table('rag_queries')->insertGetId([
            'question' => $question,
            'answer' => $answer,
            'llm_provider' => 'openrouter',
            'llm_model' => config('rag.openrouter.model'),
            'embedding_model' => config('rag.embedding.model'),
            'top_k' => count($chunks),
            'metadata' => json_encode([
                'filters' => $filters,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($chunks as $chunk) {
            DB::table('rag_query_chunks')->insert([
                'rag_query_id' => $queryId,
                'document_chunk_id' => $chunk->id,
                'distance' => $chunk->distance,
                'rank' => $chunk->rank,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return new RagAnswer(
            answer: $answer,
            sources: $chunks,
        );
    }
}
```

---

### 21.1 DTO `RagAnswer`

```php
<?php

namespace App\Domain\Rag\DTO;

final class RagAnswer
{
    public function __construct(
        public readonly string $answer,
        public readonly array $sources,
    ) {
    }
}
```

---

## 22. Neuron RAG class

Если хочешь именно закрепить Neuron как слой RAG, можно сделать `DocumentRAG`.

Команда:

```bash
php artisan neuron:rag DocumentRAG
```

Или вручную:

```php
<?php

namespace App\Neuron;

use NeuronAI\Laravel\Facades\AIProvider;
use NeuronAI\Laravel\Facades\EmbeddingProvider;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\RAG;
use NeuronAI\Providers\AIProviderInterface;

final class DocumentRAG extends RAG
{
    protected function provider(): AIProviderInterface
    {
        // В конфиге можно настроить OpenAI-compatible provider для OpenRouter.
        return AIProvider::driver('openai-like');
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        // В конфиге можно настроить Ollama embeddings provider.
        return EmbeddingProvider::driver('ollama');
    }

    public function instructions(): string
    {
        return implode("\n", [
            'Ты — RAG-помощник.',
            'Отвечай только на основе найденного контекста.',
            'Если данных не хватает, честно скажи об этом.',
            'Отвечай на русском языке.',
        ]);
    }
}
```

На практике для PostgreSQL/pgvector, скорее всего, всё равно понадобится свой `PgVectorStore` или adapter под интерфейс Neuron `VectorStoreInterface`.

Рекомендуемый подход:

```text
Neuron использовать для:
- provider abstraction;
- RAG/Agent class;
- memory/history;
- unified AI calls.

Своим кодом реализовать:
- extraction .md/.docx;
- chunking;
- metadata enrichment;
- filtering;
- PgVectorStore;
- logging.
```

Так ты получишь “нормальный функционал”, но не потеряешь контроль над документами и PostgreSQL.

---

## 23. Конфиг `config/rag.php`

```php
<?php

return [
    'embedding' => [
        'provider' => env('RAG_EMBEDDING_PROVIDER', 'ollama'),
        'model' => env('RAG_EMBEDDING_MODEL', 'bge-m3'),
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1024),
    ],

    'ollama' => [
        'base_url' => env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434'),
    ],

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model' => env('OPENROUTER_MODEL', 'openrouter/free'),
        'site_url' => env('OPENROUTER_SITE_URL', 'http://localhost'),
        'app_name' => env('OPENROUTER_APP_NAME', 'Laravel RAG Pet Project'),
    ],

    'top_k' => (int) env('RAG_TOP_K', 8),
    'max_context_chars' => (int) env('RAG_MAX_CONTEXT_CHARS', 16000),

    'chunking' => [
        'chunk_size_chars' => (int) env('RAG_CHUNK_SIZE_CHARS', 3200),
        'overlap_chars' => (int) env('RAG_CHUNK_OVERLAP_CHARS', 500),
    ],
];
```

---

## 24. API endpoints

### 24.1 Routes

```php
use App\Http\Controllers\RagDocumentController;
use App\Http\Controllers\RagChatController;

Route::prefix('rag')->group(function () {
    Route::post('/documents', [RagDocumentController::class, 'store']);
    Route::get('/documents', [RagDocumentController::class, 'index']);
    Route::get('/documents/{document}', [RagDocumentController::class, 'show']);
    Route::post('/documents/{document}/reindex', [RagDocumentController::class, 'reindex']);

    Route::post('/chat', [RagChatController::class, 'chat']);
});
```

---

### 24.2 `RagDocumentController`

```php
<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Jobs\ProcessDocumentJob;
use App\Domain\Documents\Models\Document;
use App\Domain\Documents\Services\DocumentImportService;
use Illuminate\Http\Request;

final class RagDocumentController
{
    public function store(Request $request, DocumentImportService $importService)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:md,markdown,docx'],
        ]);

        $document = $importService->import($request->file('file'));

        ProcessDocumentJob::dispatch($document->id);

        return response()->json([
            'id' => $document->id,
            'status' => $document->status,
        ], 201);
    }

    public function index()
    {
        return Document::query()
            ->latest()
            ->paginate(20);
    }

    public function show(Document $document)
    {
        return $document;
    }

    public function reindex(Document $document)
    {
        ProcessDocumentJob::dispatch($document->id);

        return response()->json([
            'id' => $document->id,
            'status' => 'queued',
        ]);
    }
}
```

---

### 24.3 `RagChatController`

```php
<?php

namespace App\Http\Controllers;

use App\Domain\Rag\Services\RagAnswerService;
use Illuminate\Http\Request;

final class RagChatController
{
    public function chat(Request $request, RagAnswerService $rag)
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:5000'],
            'document_id' => ['nullable', 'integer', 'exists:documents,id'],
        ]);

        $filters = [];

        if (! empty($data['document_id'])) {
            $filters['document_id'] = (int) $data['document_id'];
        }

        $answer = $rag->answer(
            question: $data['question'],
            filters: $filters,
        );

        return response()->json([
            'answer' => $answer->answer,
            'sources' => array_map(fn ($source) => [
                'chunk_id' => $source->id,
                'document_id' => $source->documentId,
                'document_title' => $source->metadata['document_title'] ?? null,
                'section_path' => $source->metadata['section_path'] ?? null,
                'distance' => $source->distance,
                'rank' => $source->rank,
            ], $answer->sources),
        ]);
    }
}
```

---

## 25. Алгоритм обработки изменений

### 25.1 Простая версия

При переиндексации документа:

```text
1. Извлечь новый текст.
2. Нормализовать.
3. Посчитать hash.
4. Если hash совпадает с последней версией — ничего не делать.
5. Если hash новый:
   - создать document_version;
   - пометить старые чанки is_active = false;
   - нарезать новые чанки;
   - получить embeddings;
   - сохранить новые чанки is_active = true.
```

Это уже нормально для пет-проекта.

### 25.2 Более экономная версия

Можно не пересчитывать embeddings для неизменившихся чанков:

```text
1. Новый текст → chunks.
2. Для каждого chunk считаем content_hash.
3. Сравниваем с активными chunk_hash старой версии.
4. Если hash уже был — можно переиспользовать embedding.
5. Если hash новый — вызвать embedding-модель.
6. Если старый hash исчез — пометить старый чанк inactive.
```

Но для первой версии лучше сделать полную переиндексацию документа.

---

## 26. Как обрабатывать `.md` и `.docx` отдельно

### 26.1 `.md`

Особенности:

- Заголовки уже явно размечены через `#`, `##`, `###`.
- Таблицы часто уже в markdown-формате.
- Кодовые блоки размечены через triple backticks.
- Можно сохранять `section_path`.

Рекомендуемый pipeline:

```text
.md
→ MarkdownTextExtractor
→ RagTextSanitizer
→ MarkdownAwareChunker
→ ChunkMetadataEnricher
→ ChunkFilter
→ Embedding
→ PgVectorStore
```

Для `.md` в metadata обязательно сохранять:

```text
section_path
heading
document_title
chunk_index
```

---

### 26.2 `.docx`

Особенности:

- Структура менее явная.
- Заголовки могут быть стилями Word.
- Таблицы надо вручную преобразовать в текст.
- Номера страниц обычно неочевидны.
- Колонтитулы и служебные строки могут попадать в текст.

Рекомендуемый pipeline:

```text
.docx
→ DocxTextExtractor
→ таблицы в markdown-like формат
→ RagTextSanitizer
→ RecursiveTextChunker
→ ChunkMetadataEnricher
→ ChunkFilter
→ Embedding
→ PgVectorStore
```

Для `.docx` в metadata сохранять:

```text
format = docx
document_title
original_filename
chunk_index
```

Если позже захочешь улучшить `.docx`, добавь:

```text
- определение Heading 1 / Heading 2 по стилям Word;
- сохранение таблиц отдельно;
- page_number через LibreOffice/PDF conversion, если нужно;
- извлечение изображений и OCR, если нужно.
```

---

## 27. Что логировать

Обязательно логировать:

```text
Индексация:
- document_id
- document_version_id
- extractor
- количество символов raw_text
- количество символов normalized_text
- количество чанков до фильтрации
- количество чанков после фильтрации
- embedding_model
- время embedding
- ошибки

Запрос:
- question
- embedding_model
- top_k
- найденные chunk_id
- distance каждого чанка
- итоговый размер context
- llm_model
- ответ
- latency
```

Это нужно, потому что RAG без логов тяжело отлаживать.

---

## 28. Проверка работоспособности

### 28.1 Проверить Ollama

```bash
ollama serve
ollama pull bge-m3
```

```bash
curl http://127.0.0.1:11434/api/embed \
  -d '{
    "model": "bge-m3",
    "input": "Тестовый текст для embedding"
  }'
```

### 28.2 Проверить pgvector

```sql
CREATE EXTENSION IF NOT EXISTS vector;

SELECT '[1,2,3]'::vector <=> '[1,2,4]'::vector;
```

### 28.3 Проверить OpenRouter

```bash
curl https://openrouter.ai/api/v1/chat/completions \
  -H "Authorization: Bearer $OPENROUTER_API_KEY" \
  -H "Content-Type: application/json" \
  -H "HTTP-Referer: http://localhost" \
  -H "X-Title: Laravel RAG Pet Project" \
  -d '{
    "model": "openrouter/free",
    "messages": [
      {
        "role": "user",
        "content": "Ответь кратко: что такое RAG?"
      }
    ]
  }'
```

---

## 29. Минимальный порядок реализации

### Шаг 1

Настроить PostgreSQL + pgvector.

### Шаг 2

Создать миграции:

```text
documents
document_versions
document_chunks
rag_queries
rag_query_chunks
```

### Шаг 3

Сделать загрузку `.md`.

### Шаг 4

Сделать extractor, sanitizer, chunker, filter.

### Шаг 5

Подключить Ollama `bge-m3` и сохранить embeddings в `document_chunks`.

### Шаг 6

Сделать vector search через SQL.

### Шаг 7

Сделать OpenRouter client и endpoint `/rag/chat`.

### Шаг 8

Добавить `.docx` через PHPWord.

### Шаг 9

Добавить обработку изменений через `version_hash` и `is_active`.

### Шаг 10

Добавить Neuron RAG class / Agent layer поверх готового pipeline.

---

## 30. Важные ограничения

1. Бесплатные модели OpenRouter могут иметь лимиты и меняться.
2. `openrouter/free` удобен для тестов, но для стабильности лучше выбрать конкретную модель.
3. Локальные embeddings через Ollama зависят от ресурсов компьютера.
4. `bge-m3` лучше для качества, но тяжелее, чем `nomic-embed-text`.
5. Если поменяешь embedding-модель, нужно пересоздать embeddings и изменить размерность `vector(N)`.
6. Для `.docx` извлечение структуры будет хуже, чем для `.md`, если не анализировать Word styles.

---

## 31. Рекомендуемый финальный стек

```text
Backend:
Laravel 11/12

AI/RAG:
Neuron AI
Neuron Laravel

Embeddings:
Ollama + bge-m3
vector dimensions: 1024

LLM:
OpenRouter
model: openrouter/free для старта
или конкретная free-модель из коллекции OpenRouter

Database:
PostgreSQL 16/17 local
pgvector
HNSW index cosine

Document parsing:
.md → custom MarkdownTextExtractor + MarkdownAwareChunker
.docx → PHPWord DocxTextExtractor + RecursiveTextChunker

Queue:
Laravel database queue или Redis queue

Storage:
Laravel local storage
storage/app/rag/documents
```

---

## 32. Суть пайплайна в одну схему

```text
UPLOAD DOCUMENT
    ↓
documents.status = uploaded
    ↓
ProcessDocumentJob
    ↓
TextExtractorFactory
    ├── MarkdownTextExtractor
    └── DocxTextExtractor
    ↓
RagTextSanitizer
    ↓
Chunker
    ├── MarkdownAwareChunker для .md
    └── RecursiveTextChunker для .docx
    ↓
ChunkMetadataEnricher
    ↓
ChunkFilter
    ↓
OllamaEmbeddingProvider bge-m3
    ↓
PgVectorStore
    ↓
document_chunks.embedding vector(1024)
    ↓
documents.status = indexed
```

```text
USER QUESTION
    ↓
OllamaEmbeddingProvider bge-m3
    ↓
PgVectorStore.similaritySearch()
    ↓
top-k chunks
    ↓
RagContextBuilder
    ↓
RagPromptBuilder
    ↓
OpenRouterChatClient
    ↓
answer + sources
```
