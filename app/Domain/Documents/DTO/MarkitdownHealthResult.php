<?php

declare(strict_types=1);

namespace App\Domain\Documents\DTO;

final readonly class MarkitdownHealthResult
{
    public function __construct(
        public bool $isAvailable,
        public string $status,
    ) {
    }
}
