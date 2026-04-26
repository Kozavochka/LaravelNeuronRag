<?php

declare(strict_types=1);

namespace App\Domain\Documents\DTO;

use App\Models\DocumentVersion;

final readonly class IndexingResult
{
    public function __construct(
        public bool $skipped,
        public int $chunkCount,
        public ?DocumentVersion $version,
        public string $contentHash,
    ) {
    }
}
