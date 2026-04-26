<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\TextExtraction;

use InvalidArgumentException;

final class TextExtractorFactory
{
    /**
     * @param iterable<DocumentTextExtractor> $extractors
     */
    public function __construct(
        private readonly iterable $extractors,
    ) {
    }

    public function for(string $extension, ?string $mimeType = null): DocumentTextExtractor
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($extension, $mimeType)) {
                return $extractor;
            }
        }

        throw new InvalidArgumentException("Unsupported document type [{$extension}].");
    }
}
