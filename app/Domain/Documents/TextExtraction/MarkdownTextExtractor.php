<?php

declare(strict_types=1);

namespace App\Domain\Documents\TextExtraction;

use App\Domain\Documents\DTO\ExtractedDocumentText;
use RuntimeException;

class MarkdownTextExtractor implements TextExtractorInterface
{
    public function supports(string $extension, ?string $mimeType = null): bool
    {
        return in_array(strtolower($extension), ['md', 'markdown'], true);
    }

    public function extract(string $path): ExtractedDocumentText
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException(sprintf('Unable to read markdown file [%s].', $path));
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $content = preg_replace('/<!--.*?-->/s', '', $content) ?? $content;

        preg_match_all('/^(#{1,6})\s+(.+)$/m', $content, $matches, PREG_SET_ORDER);
        $headings = [];

        foreach ($matches as $match) {
            $headings[] = trim($match[2]);
        }

        $title = $headings[0] ?? null;

        return new ExtractedDocumentText(
            content: trim($content),
            metadata: [
                'source_format' => 'markdown',
                'heading_count' => count($headings),
            ],
            headings: $headings,
            title: $title,
        );
    }
}
