<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

use App\Domain\Rag\DTO\RagChatRequest;
use App\Domain\Rag\DTO\RagChatSource;
use App\Domain\Rag\DTO\RagQueryTelemetryPayload;
use App\Domain\Rag\Support\RagRuntimeConfig;
use Illuminate\Support\Facades\DB;

final readonly class RagQueryLogger
{
    public function __construct(
        private RagRuntimeConfig $config,
    ) {
    }

    /**
     * @param RagChatSource[] $sources
     */
    public function log(
        RagChatRequest $request,
        string $answer,
        array $sources,
        ?RagQueryTelemetryPayload $telemetry = null,
    ): ?int
    {
        return DB::transaction(function () use ($request, $answer, $sources, $telemetry): ?int {
            $queryId = DB::table('rag_queries')->insertGetId([
                'user_id' => $request->userId,
                'question' => $request->question,
                'answer' => $answer,
                'llm_provider' => 'openrouter',
                'llm_model' => $this->config->openRouterModel,
                'embedding_model' => $this->config->embeddingModel,
                'top_k' => count($sources),
                'embedding_ms' => $telemetry?->metrics->embeddingMs,
                'vector_search_ms' => $telemetry?->metrics->vectorSearchMs,
                'keyword_search_ms' => $telemetry?->metrics->keywordSearchMs,
                'hybrid_merge_ms' => $telemetry?->metrics->hybridMergeMs,
                'rerank_ms' => $telemetry?->metrics->rerankMs,
                'prompt_build_ms' => $telemetry?->metrics->promptBuildMs,
                'llm_ms' => $telemetry?->metrics->llmMs,
                'total_ms' => $telemetry?->metrics->totalMs,
                'prompt_tokens' => $telemetry?->usage->promptTokens,
                'completion_tokens' => $telemetry?->usage->completionTokens,
                'total_tokens' => $telemetry?->usage->totalTokens,
                'estimated_cost_usd' => $telemetry?->estimatedCostUsd,
                'metadata' => json_encode([
                    'document_id' => $request->documentId,
                    'filters' => $request->resolvedFilters(),
                    'sources' => array_map(
                        static fn (RagChatSource $source): array => [
                            'chunk_id' => $source->chunkId,
                            'document_id' => $source->documentId,
                            'score' => $source->score,
                            'distance' => $source->distance,
                            'rerank_score' => $source->rerankScore,
                            'vector_score' => isset($source->metadata['vector_score']) ? (float) $source->metadata['vector_score'] : null,
                            'keyword_score' => isset($source->metadata['keyword_score']) ? (float) $source->metadata['keyword_score'] : null,
                            'retrieval_source' => $source->metadata['retrieval_source'] ?? null,
                            'rank' => $source->rank,
                            'vector_rank' => isset($source->metadata['vector_rank']) ? (int) $source->metadata['vector_rank'] : null,
                            'keyword_rank' => isset($source->metadata['keyword_rank']) ? (int) $source->metadata['keyword_rank'] : null,
                            'rank_after_rerank' => isset($source->metadata['rank_after_rerank']) ? (int) $source->metadata['rank_after_rerank'] : $source->rank,
                        ],
                        $sources
                    ),
                    ...($telemetry?->metadataForPersistence() ?? []),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($sources as $source) {
                if (! is_numeric($source->chunkId)) {
                    continue;
                }

                DB::table('rag_query_chunks')->insert([
                    'rag_query_id' => $queryId,
                    'document_chunk_id' => (int) $source->chunkId,
                    'distance' => $source->distance,
                    'score' => $source->score,
                    'rerank_score' => $source->rerankScore,
                    'vector_score' => isset($source->metadata['vector_score']) ? (float) $source->metadata['vector_score'] : null,
                    'keyword_score' => isset($source->metadata['keyword_score']) ? (float) $source->metadata['keyword_score'] : null,
                    'retrieval_source' => $source->metadata['retrieval_source'] ?? null,
                    'vector_rank' => isset($source->metadata['vector_rank']) ? (int) $source->metadata['vector_rank'] : null,
                    'keyword_rank' => isset($source->metadata['keyword_rank']) ? (int) $source->metadata['keyword_rank'] : null,
                    'rank' => $source->rank > 0 ? $source->rank : (isset($source->metadata['rank_after_rerank']) ? (int) $source->metadata['rank_after_rerank'] : null),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return is_numeric($queryId) ? (int) $queryId : null;
        });
    }
}
