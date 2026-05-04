# Проектное решение: Hybrid Search для LaravelNeuronRag

## 1. Цель

Добавить в проект `LaravelNeuronRag` гибридный поиск:

```text
Vector Search + Keyword Search
```

Сейчас RAG, скорее всего, ищет чанки только через embedding:

```text
question
→ embedding
→ pgvector similarity search
→ top-k chunks
→ prompt
→ LLM
```

Это хорошо работает для смыслового поиска, но хуже для точных терминов:

```text
HNSW
pgvector
DocumentVersion
RagQueryChunk
API endpoint
названия классов
ошибки из логов
аббревиатуры
```

Hybrid Search нужен, чтобы объединить:

```text
1. Vector Search — смысловая близость
2. Keyword Search — точные совпадения слов
```

---

## 2. Важное понимание

Для `search_vector` НЕ нужна embedding-модель.

Это разные механизмы.

### Vector Search

```text
chunk.content
→ embedding model
→ vector
→ document_chunks.embedding
```

### Keyword Search

```text
chunk.content
→ PostgreSQL to_tsvector()
→ document_chunks.search_vector
```

То есть:

```text
search_vector не связан с embedding
search_vector не требует Ollama/OpenAI/OpenRouter
search_vector создаёт сам PostgreSQL
```

---

## 3. Итоговый retrieval pipeline

Было:

```text
question
→ embedding(question)
→ VectorSearchService top-8
→ PromptBuilder
→ LLM
```

Стало:

```text
question
→ embedding(question)
→ RetrievalService
    → vector search top-30
    → keyword search top-30
    → merge scores
→ optional reranker
→ top-5/top-8 chunks
→ PromptBuilder
→ LLM
```

---

## 4. Режимы поиска

Нужно поддержать 3 режима:

```text
vector   — только pgvector
keyword  — только PostgreSQL full-text search
hybrid   — vector + keyword
```

Это позволит тестировать качество:

```text
?retrieval_mode=vector
?retrieval_mode=keyword
?retrieval_mode=hybrid
```

Или через config:

```env
RAG_RETRIEVAL_MODE=hybrid
```

---

## 5. Конфиг

Обновить или создать `config/rag.php`.

```php
<?php

declare(strict_types=1);

return [
    'retrieval' => [
        /*
        |--------------------------------------------------------------------------
        | Retrieval Mode
        |--------------------------------------------------------------------------
        |
        | vector  - только embedding similarity search
        | keyword - только PostgreSQL full-text search
        | hybrid  - объединение vector + keyword
        |
        */
        'mode' => env('RAG_RETRIEVAL_MODE', 'hybrid'),

        /*
        |--------------------------------------------------------------------------
        | Candidates
        |--------------------------------------------------------------------------
        |
        | vector_candidates:
        |   сколько чанков достаём из pgvector.
        |
        | keyword_candidates:
        |   сколько чанков достаём через tsvector.
        |
        | final_top_k:
        |   сколько чанков в итоге пойдёт в prompt.
        |
        */
        'vector_candidates' => (int) env('RAG_VECTOR_CANDIDATES', 30),
        'keyword_candidates' => (int) env('RAG_KEYWORD_CANDIDATES', 30),
        'final_top_k' => (int) env('RAG_FINAL_TOP_K', 8),

        /*
        |--------------------------------------------------------------------------
        | Hybrid Weights
        |--------------------------------------------------------------------------
        |
        | vector_weight:
        |   вклад смыслового поиска.
        |
        | keyword_weight:
        |   вклад точного keyword поиска.
        |
        */
        'weights' => [
            'vector' => (float) env('RAG_VECTOR_WEIGHT', 0.7),
            'keyword' => (float) env('RAG_KEYWORD_WEIGHT', 0.3),
        ],

        /*
        |--------------------------------------------------------------------------
        | PostgreSQL Full Text Search Dictionary
        |--------------------------------------------------------------------------
        |
        | simple:
        |   хороший старт для mixed language, кода, терминов.
        |
        | english:
        |   лучше для английских документов.
        |
        | russian:
        |   можно использовать для русских документов.
        |
        */
        'ts_dictionary' => env('RAG_TS_DICTIONARY', 'simple'),
    ],
];
```

В `.env`:

```env
RAG_RETRIEVAL_MODE=hybrid
RAG_VECTOR_CANDIDATES=30
RAG_KEYWORD_CANDIDATES=30
RAG_FINAL_TOP_K=8
RAG_VECTOR_WEIGHT=0.7
RAG_KEYWORD_WEIGHT=0.3
RAG_TS_DICTIONARY=simple
```

---

## 6. Изменения в базе данных

Нужно добавить `tsvector` колонку в `document_chunks`.

### 6.1. Миграция

```bash
php artisan make:migration add_search_vector_to_document_chunks_table
```

Пример миграции:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE document_chunks ADD COLUMN search_vector tsvector');

        DB::statement('
            CREATE INDEX document_chunks_search_vector_idx
            ON document_chunks
            USING GIN (search_vector)
        ');

        DB::statement("
            UPDATE document_chunks
            SET search_vector = to_tsvector('simple', coalesce(content, ''))
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS document_chunks_search_vector_idx');
        DB::statement('ALTER TABLE document_chunks DROP COLUMN IF EXISTS search_vector');
    }
};
```

Почему `DB::statement`, а не `$table->tsVector()`:

```text
В разных версиях Laravel поддержка tsvector может отличаться.
Raw SQL надёжнее для PostgreSQL.
```

---

## 7. Обновление search_vector при сохранении чанков

Когда сохраняется новый `DocumentChunk`, нужно заполнить:

```text
search_vector = to_tsvector(dictionary, content)
```

Есть 3 варианта.

---

### Вариант A. Заполнять вручную после insert

В твоём `DocumentVectorStore` или сервисе, который сохраняет чанки:

```php
$chunk = DocumentChunk::query()->create([
    'document_id' => $document->id,
    'document_version_id' => $version->id,
    'chunk_index' => $preparedChunk->chunkIndex,
    'content' => $preparedChunk->content,
    'content_hash' => $preparedChunk->contentHash(),
    'char_count' => $preparedChunk->charCount(),
    'token_estimate' => $preparedChunk->tokenEstimate(),
    'heading' => $preparedChunk->heading,
    'section_path' => implode(' / ', $preparedChunk->sectionPath),
    'page_number' => $preparedChunk->pageNumber,
    'metadata' => $preparedChunk->metadata,
    'is_active' => true,
]);

DB::statement(
    "UPDATE document_chunks SET search_vector = to_tsvector(?, coalesce(content, '')) WHERE id = ?",
    [config('rag.retrieval.ts_dictionary', 'simple'), $chunk->id],
);
```

---

### Вариант B. Generated column

Можно сделать `search_vector` generated column:

```sql
ALTER TABLE document_chunks
ADD COLUMN search_vector tsvector
GENERATED ALWAYS AS (to_tsvector('simple', coalesce(content, ''))) STORED;
```

Плюс:

```text
не нужно обновлять вручную
```

Минус:

```text
сложнее менять dictionary через config
```

Для pet-проекта можно выбрать Вариант A, потому что он проще контролируется из Laravel.

---

### Вариант C. PostgreSQL trigger

Можно сделать trigger, который сам обновляет `search_vector` при insert/update.

Плюс:

```text
надёжно на уровне БД
```

Минус:

```text
сложнее для новичка
```

Рекомендация: начать с Варианта A.

---

## 8. DTO для результатов поиска

Чтобы не передавать голые модели с динамическими полями, лучше сделать DTO.

Создать:

```text
app/Domain/Rag/DTO/RetrievedChunk.php
```

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rag\DTO;

use App\Models\DocumentChunk;

final readonly class RetrievedChunk
{
    public function __construct(
        public DocumentChunk $chunk,
        public ?float $distance = null,
        public float $vectorScore = 0.0,
        public float $keywordScore = 0.0,
        public float $score = 0.0,
        public ?int $vectorRank = null,
        public ?int $keywordRank = null,
        public ?int $finalRank = null,
        public string $source = 'unknown',
    ) {
    }

    public function withScore(
        float $score,
        ?int $finalRank = null,
    ): self {
        return new self(
            chunk: $this->chunk,
            distance: $this->distance,
            vectorScore: $this->vectorScore,
            keywordScore: $this->keywordScore,
            score: $score,
            vectorRank: $this->vectorRank,
            keywordRank: $this->keywordRank,
            finalRank: $finalRank,
            source: $this->source,
        );
    }

    public function withFinalRank(int $finalRank): self
    {
        return new self(
            chunk: $this->chunk,
            distance: $this->distance,
            vectorScore: $this->vectorScore,
            keywordScore: $this->keywordScore,
            score: $this->score,
            vectorRank: $this->vectorRank,
            keywordRank: $this->keywordRank,
            finalRank: $finalRank,
            source: $this->source,
        );
    }
}
```

---

## 9. KeywordSearchService

Создать:

```text
app/Domain/Rag/Services/KeywordSearchService.php
```

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

use App\Domain\Rag\DTO\RetrievedChunk;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;

final class KeywordSearchService
{
    /**
     * @return RetrievedChunk[]
     */
    public function search(string $query, int $limit = 30): array
    {
        $dictionary = config('rag.retrieval.ts_dictionary', 'simple');

        $rows = DocumentChunk::query()
            ->select('document_chunks.*')
            ->selectRaw(
                "ts_rank_cd(search_vector, plainto_tsquery(?, ?)) as keyword_rank_score",
                [$dictionary, $query],
            )
            ->where('is_active', true)
            ->whereRaw(
                "search_vector @@ plainto_tsquery(?, ?)",
                [$dictionary, $query],
            )
            ->orderByDesc('keyword_rank_score')
            ->limit($limit)
            ->get();

        $maxScore = max(1e-9, (float) $rows->max('keyword_rank_score'));

        return $rows
            ->values()
            ->map(function (DocumentChunk $chunk, int $index) use ($maxScore) {
                $rawScore = (float) ($chunk->keyword_rank_score ?? 0);

                return new RetrievedChunk(
                    chunk: $chunk,
                    distance: null,
                    vectorScore: 0.0,
                    keywordScore: $rawScore / $maxScore,
                    score: $rawScore / $maxScore,
                    vectorRank: null,
                    keywordRank: $index + 1,
                    finalRank: null,
                    source: 'keyword',
                );
            })
            ->all();
    }
}
```

### Почему `ts_rank_cd`

`ts_rank_cd` даёт числовой score релевантности по full-text search.

Мы нормализуем его:

```text
keyword_score = raw_score / max_raw_score
```

Так score будет примерно в диапазоне `0..1`.

---

## 10. VectorSearchService

Если у тебя уже есть свой vector search, его нужно привести к DTO `RetrievedChunk`.

Пример:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

use App\Domain\Rag\DTO\RetrievedChunk;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;

final class VectorSearchService
{
    /**
     * @param list<float> $embedding
     * @return RetrievedChunk[]
     */
    public function search(array $embedding, int $limit = 30): array
    {
        $vector = '[' . implode(',', $embedding) . ']';

        $rows = DocumentChunk::query()
            ->select('document_chunks.*')
            ->selectRaw('embedding <=> ?::vector as distance', [$vector])
            ->where('is_active', true)
            ->orderByRaw('embedding <=> ?::vector', [$vector])
            ->limit($limit)
            ->get();

        return $rows
            ->values()
            ->map(function (DocumentChunk $chunk, int $index) {
                $distance = (float) $chunk->distance;

                return new RetrievedChunk(
                    chunk: $chunk,
                    distance: $distance,
                    vectorScore: max(0.0, 1.0 - $distance),
                    keywordScore: 0.0,
                    score: max(0.0, 1.0 - $distance),
                    vectorRank: $index + 1,
                    keywordRank: null,
                    finalRank: null,
                    source: 'vector',
                );
            })
            ->all();
    }
}
```

---

## 11. HybridSearchService

Создать:

```text
app/Domain/Rag/Services/HybridSearchService.php
```

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

use App\Domain\Rag\DTO\RetrievedChunk;

final class HybridSearchService
{
    public function __construct(
        private readonly VectorSearchService $vectorSearchService,
        private readonly KeywordSearchService $keywordSearchService,
    ) {
    }

    /**
     * @param list<float> $embedding
     * @return RetrievedChunk[]
     */
    public function search(string $query, array $embedding, int $limit = 30): array
    {
        $vectorCandidates = (int) config('rag.retrieval.vector_candidates', 30);
        $keywordCandidates = (int) config('rag.retrieval.keyword_candidates', 30);

        $vectorResults = $this->vectorSearchService->search(
            embedding: $embedding,
            limit: $vectorCandidates,
        );

        $keywordResults = $this->keywordSearchService->search(
            query: $query,
            limit: $keywordCandidates,
        );

        return $this->merge(
            vectorResults: $vectorResults,
            keywordResults: $keywordResults,
            limit: $limit,
        );
    }

    /**
     * @param RetrievedChunk[] $vectorResults
     * @param RetrievedChunk[] $keywordResults
     * @return RetrievedChunk[]
     */
    private function merge(array $vectorResults, array $keywordResults, int $limit): array
    {
        $vectorWeight = (float) config('rag.retrieval.weights.vector', 0.7);
        $keywordWeight = (float) config('rag.retrieval.weights.keyword', 0.3);

        /** @var array<int, RetrievedChunk> $merged */
        $merged = [];

        foreach ($vectorResults as $result) {
            $id = $result->chunk->id;
            $merged[$id] = $result;
        }

        foreach ($keywordResults as $keywordResult) {
            $id = $keywordResult->chunk->id;

            if (! isset($merged[$id])) {
                $merged[$id] = $keywordResult;
                continue;
            }

            $existing = $merged[$id];

            $merged[$id] = new RetrievedChunk(
                chunk: $existing->chunk,
                distance: $existing->distance,
                vectorScore: $existing->vectorScore,
                keywordScore: $keywordResult->keywordScore,
                score: 0.0,
                vectorRank: $existing->vectorRank,
                keywordRank: $keywordResult->keywordRank,
                finalRank: null,
                source: 'hybrid',
            );
        }

        $scored = array_map(
            function (RetrievedChunk $result) use ($vectorWeight, $keywordWeight) {
                $score = ($vectorWeight * $result->vectorScore)
                    + ($keywordWeight * $result->keywordScore);

                return new RetrievedChunk(
                    chunk: $result->chunk,
                    distance: $result->distance,
                    vectorScore: $result->vectorScore,
                    keywordScore: $result->keywordScore,
                    score: $score,
                    vectorRank: $result->vectorRank,
                    keywordRank: $result->keywordRank,
                    finalRank: null,
                    source: $result->source === 'unknown' ? 'hybrid' : $result->source,
                );
            },
            array_values($merged),
        );

        usort(
            $scored,
            fn (RetrievedChunk $a, RetrievedChunk $b) => $b->score <=> $a->score,
        );

        return array_map(
            fn (RetrievedChunk $result, int $index) => $result->withFinalRank($index + 1),
            array_slice($scored, 0, $limit),
            array_keys(array_slice($scored, 0, $limit)),
        );
    }
}
```

---

## 12. RetrievalService как единая точка входа

Создать:

```text
app/Domain/Rag/Services/RetrievalService.php
```

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

use App\Domain\Rag\DTO\RetrievedChunk;
use InvalidArgumentException;

final class RetrievalService
{
    public function __construct(
        private readonly VectorSearchService $vectorSearchService,
        private readonly KeywordSearchService $keywordSearchService,
        private readonly HybridSearchService $hybridSearchService,
    ) {
    }

    /**
     * @param list<float> $embedding
     * @return RetrievedChunk[]
     */
    public function search(
        string $query,
        array $embedding,
        ?string $mode = null,
        ?int $limit = null,
    ): array {
        $mode ??= (string) config('rag.retrieval.mode', 'hybrid');
        $limit ??= (int) config('rag.retrieval.final_top_k', 8);

        return match ($mode) {
            'vector' => $this->vectorSearchService->search($embedding, $limit),
            'keyword' => $this->keywordSearchService->search($query, $limit),
            'hybrid' => $this->hybridSearchService->search($query, $embedding, $limit),
            default => throw new InvalidArgumentException("Unsupported retrieval mode: {$mode}"),
        };
    }
}
```

---

## 13. Встраивание в RagAnswerService

Было:

```php
$queryEmbedding = $this->embeddingService->embed($question);

$chunks = $this->vectorSearchService->search(
    embedding: $queryEmbedding,
    limit: 8,
);

$prompt = $this->promptBuilder->build($question, $chunks);
```

Стало:

```php
$queryEmbedding = $this->embeddingService->embed($question);

$retrievedChunks = $this->retrievalService->search(
    query: $question,
    embedding: $queryEmbedding,
    mode: $requestMode ?? null,
    limit: (int) config('rag.retrieval.final_top_k', 8),
);

$prompt = $this->promptBuilder->build(
    question: $question,
    chunks: $retrievedChunks,
);
```

Важно: `PromptBuilder` теперь должен уметь работать с `RetrievedChunk`.

Пример:

```php
foreach ($retrievedChunks as $retrieved) {
    $chunk = $retrieved->chunk;

    $context .= "Источник {$retrieved->finalRank}\n";
    $context .= "Документ: {$chunk->document?->title}\n";
    $context .= "Раздел: {$chunk->section_path}\n";
    $context .= "Score: {$retrieved->score}\n";
    $context .= "Текст:\n{$chunk->content}\n\n";
}
```

---

## 14. Логирование в RagQuery

В `RagQuery.metadata` сохранять:

```php
'metadata' => [
    'retrieval' => [
        'mode' => $mode,
        'vector_candidates' => config('rag.retrieval.vector_candidates'),
        'keyword_candidates' => config('rag.retrieval.keyword_candidates'),
        'final_top_k' => config('rag.retrieval.final_top_k'),
        'weights' => config('rag.retrieval.weights'),
        'ts_dictionary' => config('rag.retrieval.ts_dictionary'),
    ],
]
```

---

## 15. Логирование в RagQueryChunk

Сейчас в `rag_query_chunks` есть:

```text
distance
score
rank
```

Для полноценного debug можно добавить:

```text
vector_score
keyword_score
retrieval_source
vector_rank
keyword_rank
```

### Миграция

```bash
php artisan make:migration add_hybrid_scores_to_rag_query_chunks_table
```

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rag_query_chunks', function (Blueprint $table) {
            $table->decimal('vector_score', 10, 6)->nullable()->after('score');
            $table->decimal('keyword_score', 10, 6)->nullable()->after('vector_score');
            $table->string('retrieval_source')->nullable()->after('keyword_score');
            $table->unsignedInteger('vector_rank')->nullable()->after('retrieval_source');
            $table->unsignedInteger('keyword_rank')->nullable()->after('vector_rank');
        });
    }

    public function down(): void
    {
        Schema::table('rag_query_chunks', function (Blueprint $table) {
            $table->dropColumn([
                'vector_score',
                'keyword_score',
                'retrieval_source',
                'vector_rank',
                'keyword_rank',
            ]);
        });
    }
};
```

Обновить `RagQueryChunk::$fillable`:

```php
protected $fillable = [
    'rag_query_id',
    'document_chunk_id',
    'distance',
    'score',
    'rank',
    'vector_score',
    'keyword_score',
    'retrieval_source',
    'vector_rank',
    'keyword_rank',
];
```

Сохранение:

```php
foreach ($retrievedChunks as $retrieved) {
    RagQueryChunk::query()->create([
        'rag_query_id' => $ragQuery->id,
        'document_chunk_id' => $retrieved->chunk->id,
        'distance' => $retrieved->distance,
        'score' => $retrieved->score,
        'rank' => $retrieved->finalRank,
        'vector_score' => $retrieved->vectorScore,
        'keyword_score' => $retrieved->keywordScore,
        'retrieval_source' => $retrieved->source,
        'vector_rank' => $retrieved->vectorRank,
        'keyword_rank' => $retrieved->keywordRank,
    ]);
}
```

---

## 16. API/Debug режим

Можно добавить параметр:

```text
POST /api/rag/chat
{
  "question": "...",
  "retrieval_mode": "hybrid"
}
```

Допустимые значения:

```text
vector
keyword
hybrid
```

В request validation:

```php
$request->validate([
    'question' => ['required', 'string'],
    'retrieval_mode' => ['nullable', 'in:vector,keyword,hybrid'],
]);
```

Передать в `RagAnswerService`:

```php
$ragQuery = $this->ragAnswerService->answer(
    question: $request->string('question')->toString(),
    userId: auth()->id(),
    retrievalMode: $request->input('retrieval_mode'),
);
```

---

## 17. Blade Admin: что добавить

Если в админке отображаются RAG-запросы, добавить:

```text
retrieval mode
vector_score
keyword_score
final score
retrieval_source
vector_rank
keyword_rank
```

Это поможет сравнивать:

```text
почему hybrid выбрал этот chunk
попал ли chunk через vector
попал ли chunk через keyword
```

---

## 18. Особенности dictionary

### simple

```text
Подходит для:
- mixed language
- технических терминов
- кода
- классов
- идентификаторов
```

Рекомендация для старта:

```env
RAG_TS_DICTIONARY=simple
```

### english

```text
Лучше для английского текста.
Может стеммить слова.
```

### russian

```text
Может быть полезен для русских документов.
Но если документы mixed, лучше начать с simple.
```

---

## 19. Возможные проблемы

### 19.1. Keyword search ничего не находит

Проверить:

```sql
SELECT id, search_vector
FROM document_chunks
LIMIT 5;
```

Если `search_vector` пустой, значит он не заполняется.

---

### 19.2. plainto_tsquery плохо ищет символы

Например:

```text
C++
API/v1/users
document_id
```

Для таких случаев позже можно добавить fallback через `ILIKE`.

Пример:

```php
->orWhere('content', 'ILIKE', '%' . $query . '%')
```

Но осторожно: `ILIKE '%query%'` без индекса может быть медленным.

---

### 19.3. Scores разных типов трудно сравнивать

Поэтому мы нормализуем:

```text
vector_score = 1 - distance
keyword_score = keyword_rank_score / max_keyword_score
```

---

## 20. Acceptance Criteria

```text
1. В document_chunks появилась колонка search_vector.
2. Для новых чанков search_vector заполняется.
3. Для старых чанков search_vector заполнен миграцией.
4. Есть KeywordSearchService.
5. Есть HybridSearchService.
6. Есть RetrievalService.
7. RagAnswerService больше не вызывает VectorSearchService напрямую.
8. Поддерживаются режимы vector, keyword, hybrid.
9. По умолчанию используется hybrid.
10. RagQuery.metadata хранит retrieval-настройки.
11. RagQueryChunk хранит score, vector_score, keyword_score, rank.
12. В Blade/Admin можно увидеть, какой поиск нашёл chunk.
```

---

## 21. Порядок реализации

### Шаг 1

Добавить config `rag.retrieval`.

### Шаг 2

Добавить migration `search_vector`.

### Шаг 3

Обновить сохранение чанков, чтобы заполнять `search_vector`.

### Шаг 4

Создать `RetrievedChunk`.

### Шаг 5

Создать `KeywordSearchService`.

### Шаг 6

Адаптировать `VectorSearchService`, чтобы он возвращал `RetrievedChunk[]`.

### Шаг 7

Создать `HybridSearchService`.

### Шаг 8

Создать `RetrievalService`.

### Шаг 9

Обновить `RagAnswerService`.

### Шаг 10

Добавить логирование scores в `RagQueryChunk`.

### Шаг 11

Добавить debug вывод в Blade/Admin.

---

## 22. Итог

Hybrid Search улучшит RAG без новых моделей.

Он особенно полезен для:

```text
точных терминов
названий классов
ошибок
аббревиатур
API
коротких вопросов
```

Главная мысль:

```text
embedding ищет смысл
tsvector ищет слова
hybrid объединяет оба подхода
```
