<?php

declare(strict_types=1);

namespace Tests\Unit\Neuron\VectorStore;

use App\Domain\Rag\Services\Telemetry\RagQueryTelemetry;
use App\Neuron\VectorStore\PgVectorStore;
use NeuronAI\RAG\Document;
use ReflectionMethod;
use Tests\TestCase;

final class PgVectorStoreTest extends TestCase
{
    public function test_it_degrades_keyword_mode_to_vector_when_keyword_search_is_unavailable(): void
    {
        $telemetry = new RagQueryTelemetry();
        $store = new PgVectorStore(
            defaultRetrievalMode: 'hybrid',
            telemetry: $telemetry,
        );
        $store->withFilters(['retrieval_mode' => 'keyword']);

        $method = new ReflectionMethod($store, 'resolvedRetrievalMode');
        $method->setAccessible(true);

        $resolvedMode = $method->invoke($store);

        $this->assertSame('vector', $resolvedMode);
        $payload = $telemetry->toPersistencePayload();
        $this->assertTrue($payload->metadata['retrieval']['degraded']);
        $this->assertSame('keyword', $payload->metadata['retrieval']['requested_mode']);
        $this->assertSame('vector', $payload->metadata['retrieval']['resolved_mode']);
    }

    public function test_it_merges_hybrid_scores_and_preserves_best_ranks(): void
    {
        $store = new PgVectorStore(
            vectorWeight: 0.7,
            keywordWeight: 0.3,
        );

        $vectorDoc = new Document('Chunk A');
        $vectorDoc->id = 10;
        $vectorDoc->metadata = [
            'chunk_id' => 10,
            'vector_score' => 0.9,
            'keyword_score' => 0.0,
            'vector_rank' => 1,
            'keyword_rank' => null,
            'retrieval_source' => 'vector',
        ];
        $vectorDoc->setScore(0.9);

        $keywordDoc = new Document('Chunk A');
        $keywordDoc->id = 10;
        $keywordDoc->metadata = [
            'chunk_id' => 10,
            'vector_score' => 0.0,
            'keyword_score' => 0.5,
            'vector_rank' => null,
            'keyword_rank' => 2,
            'retrieval_source' => 'keyword',
        ];
        $keywordDoc->setScore(0.5);

        $keywordOnly = new Document('Chunk B');
        $keywordOnly->id = 12;
        $keywordOnly->metadata = [
            'chunk_id' => 12,
            'vector_score' => 0.0,
            'keyword_score' => 1.0,
            'vector_rank' => null,
            'keyword_rank' => 1,
            'retrieval_source' => 'keyword',
        ];
        $keywordOnly->setScore(1.0);

        $method = new ReflectionMethod($store, 'mergeHybridResults');
        $method->setAccessible(true);

        /** @var array<int, Document> $results */
        $results = $method->invoke($store, [$vectorDoc], [$keywordDoc, $keywordOnly], 5);

        $this->assertCount(2, $results);
        $this->assertSame(10, $results[0]->metadata['chunk_id']);
        $this->assertSame(12, $results[1]->metadata['chunk_id']);
        $this->assertSame('hybrid', $results[0]->metadata['retrieval_source']);
        $this->assertSame(1, $results[0]->metadata['rank']);
        $this->assertSame(2, $results[1]->metadata['rank']);
        $this->assertEquals(0.78, $results[0]->metadata['final_score']);
        $this->assertSame(1, $results[0]->metadata['vector_rank']);
        $this->assertSame(2, $results[0]->metadata['keyword_rank']);
    }
}
