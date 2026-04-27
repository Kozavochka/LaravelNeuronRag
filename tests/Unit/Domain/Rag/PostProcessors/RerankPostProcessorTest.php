<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Rag\PostProcessors;

use App\Domain\Rag\Contracts\RerankerInterface;
use App\Domain\Rag\PostProcessors\RerankPostProcessor;
use App\Domain\Rag\Services\Telemetry\RagQueryTelemetry;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class RerankPostProcessorTest extends TestCase
{
    #[Test]
    public function it_falls_back_to_vector_order_when_reranker_returns_empty(): void
    {
        $postProcessor = new RerankPostProcessor(
            reranker: new class implements RerankerInterface {
                public function rerank(string $query, array $chunks, int $limit): array
                {
                    return [];
                }
            },
            telemetry: new RagQueryTelemetry(),
            defaultFinalTopK: 2,
        );

        $first = new Document('First');
        $first->metadata = ['chunk_id' => 101, 'rank' => 1];

        $second = new Document('Second');
        $second->metadata = ['chunk_id' => 102, 'rank' => 2];

        $results = $postProcessor->process(UserMessage::make('q'), [$first, $second]);

        $this->assertCount(2, $results);
        $this->assertSame(101, $results[0]->metadata['chunk_id']);
        $this->assertSame(102, $results[1]->metadata['chunk_id']);
        $this->assertSame(1, $results[0]->metadata['rank_after_rerank']);
        $this->assertSame(2, $results[1]->metadata['rank_after_rerank']);
        $this->assertSame(1, $results[0]->metadata['vector_rank']);
        $this->assertSame(2, $results[1]->metadata['vector_rank']);
    }

    #[Test]
    public function it_backfills_missing_results_up_to_top_k(): void
    {
        $postProcessor = new RerankPostProcessor(
            reranker: new class implements RerankerInterface {
                public function rerank(string $query, array $chunks, int $limit): array
                {
                    return [$chunks[1]];
                }
            },
            telemetry: new RagQueryTelemetry(),
            defaultFinalTopK: 2,
        );

        $first = new Document('First');
        $first->metadata = ['chunk_id' => 201, 'rank' => 1];

        $second = new Document('Second');
        $second->metadata = ['chunk_id' => 202, 'rank' => 2];

        $third = new Document('Third');
        $third->metadata = ['chunk_id' => 203, 'rank' => 3];

        $results = $postProcessor->process(UserMessage::make('q'), [$first, $second, $third]);

        $this->assertCount(2, $results);
        $this->assertSame(202, $results[0]->metadata['chunk_id']);
        $this->assertSame(201, $results[1]->metadata['chunk_id']);
        $this->assertSame(2, $results[0]->metadata['vector_rank']);
        $this->assertSame(1, $results[1]->metadata['vector_rank']);
        $this->assertSame(1, $results[0]->metadata['rank_after_rerank']);
        $this->assertSame(2, $results[1]->metadata['rank_after_rerank']);
    }
}
