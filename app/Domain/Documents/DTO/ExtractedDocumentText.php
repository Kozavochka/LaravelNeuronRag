<?php

declare(strict_types=1);

namespace App\Domain\Documents\DTO;

final readonly class ExtractedDocumentText
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $headings
     */
    public function __construct(
        public string $content,
        public array $metadata = [],
        public array $headings = [],
        public ?string $title = null,
    ) {
    }
}
