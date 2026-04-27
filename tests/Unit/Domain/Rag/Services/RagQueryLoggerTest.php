<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Rag\Services;

use App\Domain\Rag\DTO\RagChatRequest;
use App\Domain\Rag\DTO\RagChatSource;
use App\Domain\Rag\DTO\RagQueryTelemetryMetrics;
use App\Domain\Rag\DTO\RagQueryTelemetryPayload;
use App\Domain\Rag\DTO\RagQueryUsage;
use App\Domain\Rag\Services\RagQueryLogger;
use App\Domain\Rag\Support\RagRuntimeConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RagQueryLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_logger_persists_telemetry_and_source_links(): void
    {
        $documentId = DB::table('documents')->insertGetId([
            'title' => 'Architecture',
            'original_filename' => 'architecture.md',
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'source_type' => 'upload',
            'source_path' => 'rag/documents/architecture.md',
            'status' => 'indexed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = DB::table('document_versions')->insertGetId([
            'document_id' => $documentId,
            'version_hash' => str_repeat('a', 64),
            'raw_text' => 'Neuron is a PHP AI framework.',
            'normalized_text' => 'Neuron is a PHP AI framework.',
            'status' => 'indexed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $chunkId = DB::table('document_chunks')->insertGetId([
            'document_id' => $documentId,
            'document_version_id' => $versionId,
            'chunk_index' => 0,
            'content' => 'Neuron is a PHP AI framework.',
            'content_hash' => str_repeat('b', 64),
            'char_count' => 30,
            'token_estimate' => 8,
            'metadata' => json_encode(['document_title' => 'Architecture']),
            'is_active' => true,
            'embedding' => json_encode([0.1, 0.2]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $logger = new RagQueryLogger(RagRuntimeConfig::fromConfig());

        $queryId = $logger->log(
            request: new RagChatRequest(
                question: 'What is Neuron?',
                documentId: $documentId,
                filters: ['topic' => 'architecture'],
            ),
            answer: 'Neuron is a PHP AI framework.',
            sources: [
                new RagChatSource(
                    chunkId: $chunkId,
                    documentId: $documentId,
                    content: 'Neuron is a PHP AI framework.',
                    score: 0.98,
                    distance: 0.02,
                    rerankScore: 1.12,
                    rank: 1,
                    metadata: ['document_title' => 'Architecture'],
                ),
            ],
            telemetry: new RagQueryTelemetryPayload(
                metrics: new RagQueryTelemetryMetrics(
                    embeddingMs: 12,
                    vectorSearchMs: 8,
                    llmMs: 150,
                    totalMs: 190,
                ),
                usage: new RagQueryUsage(
                    promptTokens: 100,
                    completionTokens: 50,
                    totalTokens: 150,
                    rawUsage: [
                        'input_tokens' => 100,
                        'output_tokens' => 50,
                    ],
                ),
                estimatedCostUsd: '0.00050000',
                metadata: [
                    'raw_usage' => [
                        'input_tokens' => 100,
                        'output_tokens' => 50,
                    ],
                ],
            ),
        );

        $this->assertIsInt($queryId);

        $this->assertDatabaseHas('rag_queries', [
            'id' => $queryId,
            'question' => 'What is Neuron?',
            'embedding_ms' => 12,
            'vector_search_ms' => 8,
            'llm_ms' => 150,
            'total_ms' => 190,
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'estimated_cost_usd' => 0.00050000,
        ]);

        $this->assertDatabaseHas('rag_query_chunks', [
            'rag_query_id' => $queryId,
            'document_chunk_id' => $chunkId,
            'rerank_score' => 1.12,
            'rank' => 1,
        ]);
    }
}
