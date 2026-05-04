<?php

declare(strict_types=1);

namespace App\Domain\Rag\DTO;

final readonly class RagChatRequest
{
    public function __construct(
        public string $question,
        public ?int $documentId = null,
        public ?int $userId = null,
        public ?int $topK = null,
        public ?string $retrievalMode = null,
        public array $filters = [],
    ) {
    }

    public function resolvedFilters(): array
    {
        return array_filter(
            [
                ...$this->filters,
                'document_id' => $this->documentId,
                'retrieval_mode' => $this->retrievalMode,
            ],
            static fn (mixed $value): bool => $value !== null
        );
    }
}
