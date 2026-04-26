<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services\Telemetry;

use App\Domain\Rag\DTO\RagQueryMetric;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

final readonly class TelemetryEmbeddingsProvider implements EmbeddingsProviderInterface
{
    public function __construct(
        private EmbeddingsProviderInterface $provider,
        private RagQueryTelemetry $telemetry,
    ) {
    }

    public function embedText(string $text): array
    {
        /** @var float[] $embedding */
        $embedding = $this->telemetry->measure(
            RagQueryMetric::EmbeddingMs,
            fn (): array => $this->provider->embedText($text)
        );

        return $embedding;
    }

    public function embedDocument(Document $document): Document
    {
        return $this->provider->embedDocument($document);
    }

    public function embedDocuments(array $documents): array
    {
        return $this->provider->embedDocuments($documents);
    }
}
