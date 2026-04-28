<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Rag\Services;

use App\Domain\Rag\Services\SimpleKeywordReranker;
use NeuronAI\RAG\Document;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SimpleKeywordRerankerTest extends TestCase
{
    #[Test]
    public function it_reranks_chunks_using_heading_and_content_matches(): void
    {
        $reranker = new SimpleKeywordReranker(
            contentWeight: 0.03,
            headingWeight: 0.05,
            sectionPathWeight: 0.04,
            minTokenLen: 2,
        );

        $first = new Document('General text about chunking.');
        $first->metadata = [
            'distance' => 0.10,
            'rank' => 1,
            'heading' => 'General Notes',
        ];
        $first->setScore(0.90);

        $second = new Document('This section explains markdown chunking in detail.');
        $second->metadata = [
            'distance' => 0.20,
            'rank' => 2,
            'heading' => 'Markdown Chunking',
            'section_path' => 'RAG / Markdown Chunking',
        ];
        $second->setScore(0.80);

        $results = $reranker->rerank('How markdown chunking works?', [$first, $second], 2);

        $this->assertCount(2, $results);
        $this->assertSame('Markdown Chunking', $results[0]->metadata['heading']);
        $this->assertGreaterThan(
            $results[1]->metadata['rerank_score'],
            $results[0]->metadata['rerank_score']
        );
        $this->assertSame(1, $results[0]->metadata['rank']);
        $this->assertSame(2, $results[1]->metadata['rank']);
        $this->assertSame(1, $results[0]->metadata['rank_after_rerank']);
        $this->assertSame(2, $results[1]->metadata['rank_after_rerank']);
    }

    #[Test]
    public function it_respects_limit_and_handles_empty_query_tokens(): void
    {
        $reranker = new SimpleKeywordReranker();

        $first = new Document('A');
        $first->metadata = ['distance' => 0.30, 'rank' => 1];

        $second = new Document('B');
        $second->metadata = ['distance' => 0.20, 'rank' => 2];

        $third = new Document('C');
        $third->metadata = ['distance' => 0.10, 'rank' => 3];

        $results = $reranker->rerank('  ?!?  ', [$first, $second, $third], 1);

        $this->assertCount(1, $results);
        $this->assertSame('C', $results[0]->getContent());
        $this->assertArrayHasKey('rerank_score', $results[0]->metadata);
    }
}
