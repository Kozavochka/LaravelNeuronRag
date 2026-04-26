<?php

declare(strict_types=1);

namespace App\Domain\Rag\DTO;

final readonly class RagChatResult
{
    /**
     * @param RagChatSource[] $sources
     */
    public function __construct(
        public string $answer,
        public array $sources,
        public ?int $queryId = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'sources' => array_map(
                static fn (RagChatSource $source): array => $source->toArray(),
                $this->sources
            ),
            'query_id' => $this->queryId,
        ];
    }
}
