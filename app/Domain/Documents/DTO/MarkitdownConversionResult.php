<?php

declare(strict_types=1);

namespace App\Domain\Documents\DTO;

final readonly class MarkitdownConversionResult
{
    public function __construct(
        public string $markdown,
        public string $filename,
        public ?string $contentType = null,
    ) {
    }
}
