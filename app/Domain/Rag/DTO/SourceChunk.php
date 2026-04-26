<?php

declare(strict_types=1);

namespace App\Domain\Rag\DTO;

final class SourceChunk
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int $chunkId,
        public readonly int $documentId,
        public readonly string $content,
        public readonly array $metadata,
        public readonly float $distance,
        public readonly float $score,
        public readonly int $rank,
    ) {
    }
}
