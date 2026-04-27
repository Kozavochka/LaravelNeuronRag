<?php

declare(strict_types=1);

namespace App\Domain\Rag\PostProcessors;

use App\Domain\Rag\Contracts\RerankerInterface;
use App\Domain\Rag\DTO\RagQueryMetric;
use App\Domain\Rag\Services\Telemetry\RagQueryTelemetry;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

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

        return $this->telemetry->measure(
            RagQueryMetric::RerankMs,
            fn (): array => $this->reranker->rerank(
                query: (string) ($question->getContent() ?? ''),
                chunks: $documents,
                limit: $this->finalTopK,
            )
        );
    }
}
