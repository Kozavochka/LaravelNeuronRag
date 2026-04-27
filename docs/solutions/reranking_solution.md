# Проектное решение: Reranking для LaravelNeuronRag

## 1. Цель

После vector search pgvector возвращает похожие чанки.

Но nearest vector != лучший контекст.

Нужно добавить reranking.

---

## 2. Новый query pipeline

Было:

```text
question
→ embedding
→ vector search top-8
→ prompt
→ llm
```

Стало:

```text
question
→ embedding
→ vector search top-30
→ reranker
→ top-5
→ prompt
→ llm
```

---

## 3. Что реализовать

```text
app/Domain/Rag/Contracts/RerankerInterface.php
app/Domain/Rag/Services/SimpleKeywordReranker.php
```

---

## 4. Контракт

```php
interface RerankerInterface
{
    public function rerank(string $query, array $chunks, int $limit): array;
}
```

---

## 5. Первая версия без ML модели

```php
<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

use App\Domain\Rag\Contracts\RerankerInterface;

final class SimpleKeywordReranker implements RerankerInterface
{
    public function rerank(string $query, array $chunks, int $limit): array
    {
        $words = collect(explode(' ', mb_strtolower($query)))
            ->filter()
            ->values()
            ->all();

        foreach ($chunks as &$chunk) {
            $score = 1 - ($chunk->distance ?? 1);

            $text = mb_strtolower($chunk->content);

            foreach ($words as $word) {
                if (str_contains($text, $word)) {
                    $score += 0.03;
                }
            }

            if (! empty($chunk->metadata['heading'])) {
                $heading = mb_strtolower($chunk->metadata['heading']);

                foreach ($words as $word) {
                    if (str_contains($heading, $word)) {
                        $score += 0.05;
                    }
                }
            }

            $chunk->rerank_score = $score;
        }

        usort($chunks, fn($a, $b) => $b->rerank_score <=> $a->rerank_score);

        return array_slice($chunks, 0, $limit);
    }
}
```

---

## 6. Где встроить

В `RagAnswerService`

Было:

```php
$chunks = $this->vectorSearch->search($embedding, 8);
```

Стало:

```php
$candidates = $this->vectorSearch->search($embedding, 30);

$chunks = $this->reranker->rerank(
    query: $question,
    chunks: $candidates,
    limit: 5,
);
```

---

## 7. Prompt Builder

```php
$prompt = $this->promptBuilder->build(
    question: $question,
    chunks: $chunks,
);
```

---

## 8. Что писать в БД

В `rag_query_chunks`

```text
distance
rerank_score
rank
```

---

## 9. Конфиг

```php
'rag' => [
    'retrieval' => [
        'vector_candidates' => 30,
        'rerank_top_k' => 5,
    ],
]
```

---

## 10. Позже можно улучшить

```text
cross-encoder reranker
bge-reranker
cohere rerank api
jina reranker
```

---

## 11. Почему полезно

Запрос:

```text
Как работает markdown chunking?
```

Vector search может найти:

```text
chunking general text
```

Reranker поднимет выше:

```text
heading = Markdown Chunking
```

---

## 12. Acceptance Criteria

```text
1. vector search получает top-30
2. reranker сортирует кандидатов
3. llm получает top-5
4. scores сохраняются в БД
5. качество ответов выше
```
