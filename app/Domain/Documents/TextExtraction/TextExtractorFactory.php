<?php

declare(strict_types=1);

namespace App\Domain\Documents\TextExtraction;

use InvalidArgumentException;

class TextExtractorFactory
{
    /**
     * @param  list<TextExtractorInterface>|null  $extractors
     */
    public function __construct(
        private readonly ?array $extractors = null,
    ) {
    }

    public function for(string $extension, ?string $mimeType = null): TextExtractorInterface
    {
        foreach ($this->extractors ?? $this->defaultExtractors() as $extractor) {
            if ($extractor->supports($extension, $mimeType)) {
                return $extractor;
            }
        }

        throw new InvalidArgumentException(sprintf(
            'Unsupported document format [%s] with mime type [%s].',
            $extension,
            $mimeType ?? 'n/a',
        ));
    }

    /**
     * @return list<TextExtractorInterface>
     */
    private function defaultExtractors(): array
    {
        return [
            new MarkdownTextExtractor(),
            new DocxTextExtractor(),
        ];
    }
}
