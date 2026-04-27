<?php

declare(strict_types=1);

namespace App\Domain\Rag\PostProcessors;

use App\Domain\Rag\Contracts\RerankerInterface;
use App\Domain\Rag\DTO\RagQueryMetric;
use App\Domain\Rag\Services\Telemetry\RagQueryTelemetry;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

use function array_slice;
use function array_values;

final class RerankPostProcessor implements PostProcessorInterface
{
    private int $finalTopK;

    public function __construct(
        private readonly RerankerInterface $reranker,
        private readonly RagQueryTelemetry $telemetry,
        int $defaultFinalTopK,
    ) {
        $this->finalTopK = max(1, $defaultFinalTopK);
    }

    public function withFinalTopK(int $topK): self
    {
        $this->finalTopK = max(1, $topK);

        return $this;
    }

    public function resetRuntimeState(int $defaultFinalTopK): self
    {
        $this->finalTopK = max(1, $defaultFinalTopK);

        return $this;
    }

    /**
     * @param Document[] $documents
     * @return Document[]
     */
    public function process(Message $question, array $documents): array
    {
        if ($documents === []) {
            return [];
        }

        $reranked = $this->telemetry->measure(
            RagQueryMetric::RerankMs,
            fn (): array => $this->reranker->rerank(
                query: (string) ($question->getContent() ?? ''),
                chunks: $documents,
                limit: $this->finalTopK,
            )
        );

        return $this->normalizeResults($documents, $reranked);
    }

    /**
     * @param array<int, Document> $original
     * @param array<int, Document> $reranked
     * @return array<int, Document>
     */
    private function normalizeResults(array $original, array $reranked): array
    {
        if ($reranked === []) {
            return $this->markRanks(array_slice(array_values($original), 0, $this->finalTopK));
        }

        if (count($reranked) < $this->finalTopK) {
            $selectedIds = [];

            foreach ($reranked as $doc) {
                $selectedIds[(string) ($doc->metadata['chunk_id'] ?? $doc->id)] = true;
            }

            foreach ($original as $doc) {
                $id = (string) ($doc->metadata['chunk_id'] ?? $doc->id);

                if (isset($selectedIds[$id])) {
                    continue;
                }

                $reranked[] = $doc;
                $selectedIds[$id] = true;

                if (count($reranked) >= $this->finalTopK) {
                    break;
                }
            }
        }

        return $this->markRanks(array_slice(array_values($reranked), 0, $this->finalTopK));
    }

    /**
     * @param array<int, Document> $documents
     * @return array<int, Document>
     */
    private function markRanks(array $documents): array
    {
        foreach ($documents as $index => $document) {
            $vectorRank = (int) ($document->metadata['vector_rank'] ?? $document->metadata['rank'] ?? $index + 1);
            $document->metadata['rank'] = $index + 1;
            $document->metadata['rank_after_rerank'] = $index + 1;
            $document->metadata['vector_rank'] = $vectorRank;
        }

        return $documents;
    }
}
