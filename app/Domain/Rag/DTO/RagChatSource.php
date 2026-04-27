<?php

declare(strict_types=1);

namespace App\Domain\Rag\DTO;

use NeuronAI\RAG\Document;

final readonly class RagChatSource
{
    public function __construct(
        public int|string $chunkId,
        public ?int $documentId,
        public string $content,
        public float $score,
        public ?float $distance,
        public ?float $rerankScore,
        public int $rank,
        public array $metadata,
    ) {
    }

    public static function fromNeuronDocument(Document $document, int $rank): self
    {
        $metadata = $document->metadata;
        $chunkId = $metadata['chunk_id'] ?? $document->getId();
        $documentId = isset($metadata['document_id']) ? (int) $metadata['document_id'] : null;

        return new self(
            chunkId: is_numeric($chunkId) ? (int) $chunkId : $chunkId,
            documentId: $documentId,
            content: $document->getContent(),
            score: (float) $document->getScore(),
            distance: isset($metadata['distance']) ? (float) $metadata['distance'] : null,
            rerankScore: isset($metadata['rerank_score']) ? (float) $metadata['rerank_score'] : null,
            rank: isset($metadata['rank']) ? (int) $metadata['rank'] : $rank,
            metadata: $metadata,
        );
    }

    public function toArray(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'content' => $this->content,
            'score' => $this->score,
            'distance' => $this->distance,
            'rerank_score' => $this->rerankScore,
            'rank' => $this->rank,
            'metadata' => $this->metadata,
        ];
    }
}
