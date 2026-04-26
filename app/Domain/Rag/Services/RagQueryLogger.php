<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

use App\Domain\Rag\DTO\RagChatRequest;
use App\Domain\Rag\DTO\RagChatSource;
use App\Domain\Rag\Support\RagRuntimeConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final readonly class RagQueryLogger
{
    public function __construct(
        private RagRuntimeConfig $config,
    ) {
    }

    /**
     * @param RagChatSource[] $sources
     */
    public function log(RagChatRequest $request, string $answer, array $sources): ?int
    {
        if (! Schema::hasTable('rag_queries')) {
            return null;
        }

        return DB::transaction(function () use ($request, $answer, $sources): ?int {
            $queryId = DB::table('rag_queries')->insertGetId([
                'user_id' => $request->userId,
                'question' => $request->question,
                'answer' => $answer,
                'llm_provider' => 'openrouter',
                'llm_model' => $this->config->openRouterModel,
                'embedding_model' => $this->config->embeddingModel,
                'top_k' => count($sources),
                'metadata' => json_encode([
                    'document_id' => $request->documentId,
                    'filters' => $request->resolvedFilters(),
                    'sources' => array_map(
                        static fn (RagChatSource $source): array => [
                            'chunk_id' => $source->chunkId,
                            'document_id' => $source->documentId,
                            'score' => $source->score,
                            'distance' => $source->distance,
                            'rank' => $source->rank,
                        ],
                        $sources
                    ),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (Schema::hasTable('rag_query_chunks')) {
                foreach ($sources as $source) {
                    if (! is_numeric($source->chunkId)) {
                        continue;
                    }

                    DB::table('rag_query_chunks')->insert([
                        'rag_query_id' => $queryId,
                        'document_chunk_id' => (int) $source->chunkId,
                        'distance' => $source->distance,
                        'score' => $source->score,
                        'rank' => $source->rank,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return is_numeric($queryId) ? (int) $queryId : null;
        });
    }
}
