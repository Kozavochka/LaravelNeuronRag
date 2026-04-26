<?php

declare(strict_types=1);

namespace App\Domain\Documents\DTO;

final readonly class PreparedChunk
{
    /**
     * @param  list<string>  $sectionPath
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $content,
        public ?string $heading = null,
        public array $sectionPath = [],
        public array $metadata = [],
        public ?int $charStart = null,
        public ?int $charEnd = null,
        public ?int $chunkIndex = null,
        public ?string $contentHash = null,
        public ?int $tokenCount = null,
        public ?int $pageNumber = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            content: $this->content,
            heading: $this->heading,
            sectionPath: $this->sectionPath,
            metadata: [...$this->metadata, ...$metadata],
            charStart: $this->charStart,
            charEnd: $this->charEnd,
            chunkIndex: $this->chunkIndex,
            contentHash: $this->contentHash,
            tokenCount: $this->tokenCount,
            pageNumber: $this->pageNumber,
        );
    }

    public function withChunkIndex(int $chunkIndex): self
    {
        return new self(
            content: $this->content,
            heading: $this->heading,
            sectionPath: $this->sectionPath,
            metadata: $this->metadata,
            charStart: $this->charStart,
            charEnd: $this->charEnd,
            chunkIndex: $chunkIndex,
            contentHash: $this->contentHash,
            tokenCount: $this->tokenCount,
            pageNumber: $this->pageNumber,
        );
    }

    public function withContentHash(string $contentHash): self
    {
        return new self(
            content: $this->content,
            heading: $this->heading,
            sectionPath: $this->sectionPath,
            metadata: $this->metadata,
            charStart: $this->charStart,
            charEnd: $this->charEnd,
            chunkIndex: $this->chunkIndex,
            contentHash: $contentHash,
            tokenCount: $this->tokenCount,
            pageNumber: $this->pageNumber,
        );
    }

    public function withTokenCount(int $tokenCount): self
    {
        return new self(
            content: $this->content,
            heading: $this->heading,
            sectionPath: $this->sectionPath,
            metadata: $this->metadata,
            charStart: $this->charStart,
            charEnd: $this->charEnd,
            chunkIndex: $this->chunkIndex,
            contentHash: $this->contentHash,
            tokenCount: $tokenCount,
            pageNumber: $this->pageNumber,
        );
    }

    public function contentHash(): string
    {
        return $this->contentHash ?? hash('sha256', $this->content);
    }

    public function charCount(): int
    {
        return max(0, ($this->charEnd ?? mb_strlen($this->content)) - ($this->charStart ?? 0));
    }

    public function tokenEstimate(): int
    {
        return $this->tokenCount ?? max(1, (int) ceil(mb_strlen($this->content) / 4));
    }
}
