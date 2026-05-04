<?php

declare(strict_types=1);

namespace App\Domain\Rag\DTO;

final readonly class RagQueryTelemetryMetrics
{
    public function __construct(
        public ?int $embeddingMs = null,
        public ?int $vectorSearchMs = null,
        public ?int $keywordSearchMs = null,
        public ?int $hybridMergeMs = null,
        public ?int $rerankMs = null,
        public ?int $promptBuildMs = null,
        public ?int $llmMs = null,
        public ?int $totalMs = null,
    ) {
    }

    /**
     * @return array<string, int|null>
     */
    public function toArray(): array
    {
        return [
            'embedding_ms' => $this->embeddingMs,
            'vector_search_ms' => $this->vectorSearchMs,
            'keyword_search_ms' => $this->keywordSearchMs,
            'hybrid_merge_ms' => $this->hybridMergeMs,
            'rerank_ms' => $this->rerankMs,
            'prompt_build_ms' => $this->promptBuildMs,
            'llm_ms' => $this->llmMs,
            'total_ms' => $this->totalMs,
        ];
    }
}
