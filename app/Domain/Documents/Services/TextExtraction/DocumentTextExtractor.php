<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\TextExtraction;

use App\Domain\Documents\DTO\ExtractedDocumentText;

interface DocumentTextExtractor
{
    public function supports(string $extension, ?string $mimeType = null): bool;

    public function extract(string $path): ExtractedDocumentText;
}
