<?php

declare(strict_types=1);

namespace App\Domain\Rag\DTO;

final class RagAnswer
{
    /**
     * @param array<int, SourceChunk> $sources
     */
    public function __construct(
        public readonly string $answer,
        public readonly array $sources,
    ) {
    }
}
