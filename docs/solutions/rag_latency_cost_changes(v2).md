# План изменений: Latency per query и Cost per query для LaravelNeuronRag

## 1. Цель изменений

Нужно добавить в RAG-проект измерение:

1. **Latency per query** — сколько времени занимает каждый этап обработки пользовательского запроса.
2. **Cost per query** — сколько примерно стоит один запрос к LLM с учетом количества токенов и тарифа модели.

Это нужно для отладки, оптимизации и сравнения разных моделей/провайдеров.

---

## 2. Что уже есть в проекте

В проекте уже есть подходящие модели:

- `RagQuery` — хранит пользовательский вопрос, ответ, модель, провайдера, embedding-модель, `top_k`, `metadata`.
- `RagQueryChunk` — хранит связь RAG-запроса с найденными чанками: `document_chunk_id`, `distance`, `score`, `rank`.
- `DocumentChunk` — хранит чанки документов, metadata и embedding.

Поэтому новые метрики лучше добавлять вокруг `RagQuery`.

---

## 3. Какие этапы нужно измерять

Для каждого пользовательского запроса нужно измерять:

```text
question
→ embedding вопроса
→ vector search
→ reranking, если есть
→ prompt build
→ LLM request
→ save logs
```

Рекомендуемые latency-поля:

```text
embedding_ms
vector_search_ms
rerank_ms
prompt_build_ms
llm_ms
total_ms
```

Рекомендуемые token/cost-поля:

```text
prompt_tokens
completion_tokens
total_tokens
estimated_cost_usd
```

---

## 4. Миграция для таблицы rag_queries

Создать миграцию:

```bash
php artisan make:migration add_metrics_to_rag_queries_table
```

Пример миграции:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rag_queries', function (Blueprint $table) {
            $table->unsignedInteger('embedding_ms')->nullable()->after('top_k');
            $table->unsignedInteger('vector_search_ms')->nullable()->after('embedding_ms');
            $table->unsignedInteger('rerank_ms')->nullable()->after('vector_search_ms');
            $table->unsignedInteger('prompt_build_ms')->nullable()->after('rerank_ms');
            $table->unsignedInteger('llm_ms')->nullable()->after('prompt_build_ms');
            $table->unsignedInteger('total_ms')->nullable()->after('llm_ms');

            $table->unsignedInteger('prompt_tokens')->nullable()->after('total_ms');
            $table->unsignedInteger('completion_tokens')->nullable()->after('prompt_tokens');
            $table->unsignedInteger('total_tokens')->nullable()->after('completion_tokens');

            $table->decimal('estimated_cost_usd', 12, 8)->nullable()->after('total_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('rag_queries', function (Blueprint $table) {
            $table->dropColumn([
                'embedding_ms',
                'vector_search_ms',
                'rerank_ms',
                'prompt_build_ms',
                'llm_ms',
                'total_ms',
                'prompt_tokens',
                'completion_tokens',
                'total_tokens',
                'estimated_cost_usd',
            ]);
        });
    }
};
```

---

## 5. Обновление модели RagQuery

В `app/Models/RagQuery.php` добавить новые поля в `$fillable`.

```php
protected $fillable = [
    'user_id',
    'question',
    'answer',
    'llm_provider',
    'llm_model',
    'embedding_model',
    'top_k',

    'embedding_ms',
    'vector_search_ms',
    'rerank_ms',
    'prompt_build_ms',
    'llm_ms',
    'total_ms',

    'prompt_tokens',
    'completion_tokens',
    'total_tokens',
    'estimated_cost_usd',

    'metadata',
];
```

Также можно добавить casts:

```php
protected function casts(): array
{
    return [
        'metadata' => 'array',
        'estimated_cost_usd' => 'decimal:8',
    ];
}
```

---

## 6. Конфиг стоимости моделей

В `config/rag.php` добавить блок стоимости моделей.

Пример:

```php
return [
    'llm' => [
        'provider' => env('RAG_LLM_PROVIDER', 'openrouter'),
        'model' => env('OPENROUTER_MODEL', 'openrouter/free'),
    ],

    'embeddings' => [
        'provider' => env('RAG_EMBEDDING_PROVIDER', 'ollama'),
        'model' => env('OLLAMA_EMBEDDING_MODEL', 'bge-m3'),
    ],

    'retrieval' => [
        'top_k' => env('RAG_TOP_K', 8),
        'rerank_top_k' => env('RAG_RERANK_TOP_K', 5),
        'vector_candidates' => env('RAG_VECTOR_CANDIDATES', 30),
    ],

    'costs' => [
        'models' => [
            // Бесплатный роутер/модель. Стоимость считаем нулевой.
            'openrouter/free' => [
                'input_per_1m' => 0,
                'output_per_1m' => 0,
            ],

            // Примеры для будущего, значения нужно заполнять вручную
            // по актуальной странице модели в OpenRouter.
            'google/gemini-2.0-flash-exp:free' => [
                'input_per_1m' => 0,
                'output_per_1m' => 0,
            ],

            'meta-llama/llama-3.1-8b-instruct:free' => [
                'input_per_1m' => 0,
                'output_per_1m' => 0,
            ],
        ],
    ],
];
```

В `.env`:

```env
RAG_LLM_PROVIDER=openrouter
OPENROUTER_MODEL=openrouter/free

RAG_EMBEDDING_PROVIDER=ollama
OLLAMA_EMBEDDING_MODEL=bge-m3

RAG_TOP_K=8
RAG_VECTOR_CANDIDATES=30
RAG_RERANK_TOP_K=5
```

---

## 7. LatencyTracker

Создать файл:

```text
app/Domain/Rag/Services/LatencyTracker.php
```

Код:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

final class LatencyTracker
{
    private float $startedAt;

    /**
     * @var array<string, int>
     */
    private array $marks = [];

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    public function measure(string $name, callable $callback): mixed
    {
        $start = microtime(true);

        try {
            return $callback();
        } finally {
            $this->marks[$name] = (int) round((microtime(true) - $start) * 1000);
        }
    }

    public function totalMs(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }

    public function get(string $name): ?int
    {
        return $this->marks[$name] ?? null;
    }

    /**
     * @return array<string, int>
     */
    public function all(): array
    {
        return $this->marks;
    }
}
```

Названия меток лучше делать такими же, как поля в БД:

```text
embedding_ms
vector_search_ms
rerank_ms
prompt_build_ms
llm_ms
```

---

## 8. CostEstimator

Создать файл:

```text
app/Domain/Rag/Services/CostEstimator.php
```

Код:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

final class CostEstimator
{
    public function estimate(
        string $model,
        ?int $promptTokens,
        ?int $completionTokens,
    ): float {
        $promptTokens ??= 0;
        $completionTokens ??= 0;

        $prices = config("rag.costs.models.$model");

        if (! is_array($prices)) {
            return 0.0;
        }

        $inputPerMillion = (float) ($prices['input_per_1m'] ?? 0);
        $outputPerMillion = (float) ($prices['output_per_1m'] ?? 0);

        return ($promptTokens / 1_000_000 * $inputPerMillion)
            + ($completionTokens / 1_000_000 * $outputPerMillion);
    }
}
```

Для бесплатных моделей стоимость будет `0`, но токены всё равно нужно сохранять.

---

## 9. DTO для ответа LLM

Чтобы удобно передавать текст ответа и usage, можно добавить DTO:

```text
app/Domain/Rag/DTO/LlmAnswer.php
```

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rag\DTO;

final readonly class LlmAnswer
{
    public function __construct(
        public string $content,
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $totalTokens = null,
        public array $rawUsage = [],
    ) {
    }
}
```

Если Neuron/OpenRouter не возвращает usage напрямую, можно временно оставлять эти поля `null`, а полный сырой ответ сохранять в `metadata`.

---

## 10. Изменение сервиса ответа RAG

Если сейчас у тебя есть сервис, который обрабатывает вопрос пользователя, его нужно обернуть в `LatencyTracker`.

Примерная структура:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

use App\Models\RagQuery;
use App\Models\RagQueryChunk;

final class RagAnswerService
{
    public function __construct(
        private readonly QueryEmbeddingService $embeddingService,
        private readonly VectorSearchService $vectorSearchService,
        private readonly PromptBuilderService $promptBuilder,
        private readonly LlmService $llmService,
        private readonly CostEstimator $costEstimator,
    ) {
    }

    public function answer(string $question, ?int $userId = null): RagQuery
    {
        $latency = new LatencyTracker();

        $topK = (int) config('rag.retrieval.top_k', 8);
        $llmModel = (string) config('rag.llm.model');
        $embeddingModel = (string) config('rag.embeddings.model');

        $queryEmbedding = $latency->measure('embedding_ms', function () use ($question) {
            return $this->embeddingService->embed($question);
        });

        $chunks = $latency->measure('vector_search_ms', function () use ($queryEmbedding, $topK) {
            return $this->vectorSearchService->search(
                embedding: $queryEmbedding,
                limit: $topK,
            );
        });

        $prompt = $latency->measure('prompt_build_ms', function () use ($question, $chunks) {
            return $this->promptBuilder->build(
                question: $question,
                chunks: $chunks,
            );
        });

        $llmAnswer = $latency->measure('llm_ms', function () use ($prompt) {
            return $this->llmService->ask($prompt);
        });

        $estimatedCost = $this->costEstimator->estimate(
            model: $llmModel,
            promptTokens: $llmAnswer->promptTokens,
            completionTokens: $llmAnswer->completionTokens,
        );

        $ragQuery = RagQuery::query()->create([
            'user_id' => $userId,
            'question' => $question,
            'answer' => $llmAnswer->content,
            'llm_provider' => config('rag.llm.provider', 'openrouter'),
            'llm_model' => $llmModel,
            'embedding_model' => $embeddingModel,
            'top_k' => $topK,

            'embedding_ms' => $latency->get('embedding_ms'),
            'vector_search_ms' => $latency->get('vector_search_ms'),
            'rerank_ms' => $latency->get('rerank_ms'),
            'prompt_build_ms' => $latency->get('prompt_build_ms'),
            'llm_ms' => $latency->get('llm_ms'),
            'total_ms' => $latency->totalMs(),

            'prompt_tokens' => $llmAnswer->promptTokens,
            'completion_tokens' => $llmAnswer->completionTokens,
            'total_tokens' => $llmAnswer->totalTokens,
            'estimated_cost_usd' => $estimatedCost,

            'metadata' => [
                'latency' => $latency->all(),
                'raw_usage' => $llmAnswer->rawUsage,
            ],
        ]);

        foreach ($chunks as $rank => $chunk) {
            RagQueryChunk::query()->create([
                'rag_query_id' => $ragQuery->id,
                'document_chunk_id' => $chunk->id,
                'distance' => $chunk->distance ?? null,
                'score' => $chunk->score ?? null,
                'rank' => $rank + 1,
            ]);
        }

        return $ragQuery;
    }
}
```

---

## 11. Если используется Neuron RAG как оркестратор

Если весь запрос сейчас вызывается примерно так:

```php
$response = DocumentRAG::make()->answer($question);
```

то есть два варианта.

### Вариант 1. Простое логирование вокруг общего вызова

Можно измерить только total/llm-like время:

```php
$latency = new LatencyTracker();

$response = $latency->measure('total_rag_ms', function () use ($question) {
    return DocumentRAG::make()->answer($question);
});
```

Минус: не видно отдельно embedding, vector search, prompt build, LLM.

### Вариант 2. Вынести этапы в свой сервис-оркестратор

Лучше для учебного проекта:

```text
RagAnswerService
→ EmbeddingService
→ VectorSearchService
→ PromptBuilderService
→ LlmService/Neuron provider
```

Так ты явно контролируешь и измеряешь каждый этап. Neuron при этом можно оставить как слой provider-ов и RAG-компонентов.

---

## 12. Логирование найденных чанков

Таблица `rag_query_chunks` уже подходит.

После поиска сохранять:

```php
foreach ($chunks as $rank => $chunk) {
    RagQueryChunk::query()->create([
        'rag_query_id' => $ragQuery->id,
        'document_chunk_id' => $chunk->id,
        'distance' => $chunk->distance,
        'score' => $chunk->score ?? null,
        'rank' => $rank + 1,
    ]);
}
```

Если позже появится reranking:

```text
distance = исходная близость из pgvector
score = итоговый rerank-score
rank = позиция после reranking
```

---

## 13. Reranking и latency

Когда появится reranking, пайплайн станет таким:

```text
question
→ embedding
→ vector search top-30
→ reranker top-5
→ prompt
→ LLM
```

Тогда `vector_search_ms` измеряет поиск top-30, а `rerank_ms` измеряет пересортировку.

Пример:

```php
$candidates = $latency->measure('vector_search_ms', function () use ($queryEmbedding) {
    return $this->vectorSearchService->search(
        embedding: $queryEmbedding,
        limit: (int) config('rag.retrieval.vector_candidates', 30),
    );
});

$chunks = $latency->measure('rerank_ms', function () use ($question, $candidates) {
    return $this->reranker->rerank(
        question: $question,
        chunks: $candidates,
        limit: (int) config('rag.retrieval.rerank_top_k', 5),
    );
});
```

---

## 14. Как отображать метрики в API

Ответ API можно сделать таким:

```json
{
  "answer": "Ответ модели...",
  "sources": [
    {
      "document_chunk_id": 10,
      "rank": 1,
      "distance": 0.21,
      "score": null
    }
  ],
  "metrics": {
    "latency": {
      "embedding_ms": 120,
      "vector_search_ms": 25,
      "prompt_build_ms": 3,
      "llm_ms": 4200,
      "total_ms": 4380
    },
    "tokens": {
      "prompt_tokens": 1200,
      "completion_tokens": 300,
      "total_tokens": 1500
    },
    "estimated_cost_usd": 0
  }
}
```

Для пользователя можно не показывать метрики, но для debug/admin-режима они очень полезны.

---

## 15. Что считать успешной реализацией

Минимальный результат:

```text
1. После каждого RAG-запроса создается запись в rag_queries.
2. В rag_queries сохраняются:
   - question
   - answer
   - llm_model
   - embedding_model
   - top_k
   - embedding_ms
   - vector_search_ms
   - prompt_build_ms
   - llm_ms
   - total_ms
   - prompt_tokens
   - completion_tokens
   - total_tokens
   - estimated_cost_usd
3. В rag_query_chunks сохраняются найденные чанки.
4. В metadata сохраняется техническая отладочная информация.
```

---

## 16. Рекомендуемый порядок внедрения

### Шаг 1. Миграция

Добавить поля latency/cost в `rag_queries`.

### Шаг 2. Модель

Обновить `$fillable` и `casts` в `RagQuery`.

### Шаг 3. LatencyTracker

Добавить сервис измерения времени.

### Шаг 4. CostEstimator

Добавить сервис расчета стоимости.

### Шаг 5. Обернуть текущий RAG-запрос

Измерить сначала хотя бы:

```text
total_ms
llm_ms
```

Потом постепенно разделить на:

```text
embedding_ms
vector_search_ms
prompt_build_ms
llm_ms
```

### Шаг 6. Сохранять чанки

Сохранять найденные чанки в `rag_query_chunks`.

### Шаг 7. Добавить вывод метрик

Вернуть метрики в debug API или показывать в админке.

---

## 17. Важное замечание по OpenRouter

Для бесплатных моделей стоимость обычно будет `0`, но:

1. лимиты могут меняться;
2. список бесплатных моделей может меняться;
3. usage/tokens могут зависеть от конкретной модели и ответа API;
4. стоимость лучше хранить в конфиге, а не зашивать в код.

Поэтому `CostEstimator` должен быть независимым от OpenRouter и считать стоимость по локальному конфигу.

---

## 18. Итоговая схема

```text
RagAnswerService
    ↓
LatencyTracker
    ↓
EmbeddingService
    ↓
VectorSearchService
    ↓
PromptBuilderService
    ↓
LlmService
    ↓
CostEstimator
    ↓
RagQuery + RagQueryChunk
```

Эта схема позволит дальше сравнивать разные варианты:

```text
bge-m3 + openrouter/free
nomic-embed-text + openrouter/free
bge-m3 + локальная LLM
top_k=5
top_k=8
top_k=15
vector search без rerank
vector search + rerank
```

Главная польза: ты сможешь видеть не только ответ модели, но и техническую цену этого ответа — время, токены, стоимость и использованные источники.
