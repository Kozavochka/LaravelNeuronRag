<?php

declare(strict_types=1);

namespace App\Domain\Rag\DTO;

enum RagQueryMetric: string
{
    case EmbeddingMs = 'embedding_ms';
    case VectorSearchMs = 'vector_search_ms';
    case RerankMs = 'rerank_ms';
    case PromptBuildMs = 'prompt_build_ms';
    case LlmMs = 'llm_ms';
    case TotalMs = 'total_ms';

    /**
     * @return array<string, int|null>
     */
    public static function defaults(): array
    {
        return array_fill_keys(
            array_map(
                static fn (self $metric): string => $metric->value,
                self::cases(),
            ),
            null,
        );
    }
}
