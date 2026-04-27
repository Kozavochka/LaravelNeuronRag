<?php

declare(strict_types=1);

namespace App\Domain\Rag\Contracts;

interface RerankerInterface
{
    /**
     * @param array<int, mixed> $chunks
     * @return array<int, mixed>
     */
    public function rerank(string $query, array $chunks, int $limit): array;
}
