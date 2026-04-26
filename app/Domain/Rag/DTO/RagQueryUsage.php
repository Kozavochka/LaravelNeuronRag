<?php

declare(strict_types=1);

namespace App\Domain\Rag\DTO;

final readonly class RagQueryUsage
{
    /**
     * @param array<string, mixed> $rawUsage
     */
    public function __construct(
        public ?int $promptTokens = null,
        public ?int $completionTokens = null,
        public ?int $totalTokens = null,
        public array $rawUsage = [],
    ) {
    }
}
