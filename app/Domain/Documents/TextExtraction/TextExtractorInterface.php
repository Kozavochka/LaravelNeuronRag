<?php

declare(strict_types=1);

namespace App\Domain\Documents\TextExtraction;

use App\Domain\Documents\DTO\ExtractedDocumentText;

interface TextExtractorInterface
{
    public function supports(string $extension, ?string $mimeType = null): bool;

    public function extract(string $path): ExtractedDocumentText;
}
