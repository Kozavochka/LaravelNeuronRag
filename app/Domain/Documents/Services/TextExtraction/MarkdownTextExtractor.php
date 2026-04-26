<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\TextExtraction;

use App\Domain\Documents\DTO\ExtractedDocumentText;
use RuntimeException;

final class MarkdownTextExtractor implements DocumentTextExtractor
{
    public function supports(string $extension, ?string $mimeType = null): bool
    {
        return in_array(mb_strtolower($extension), ['md', 'markdown'], true);
    }

    public function extract(string $path): ExtractedDocumentText
    {
        $text = file_get_contents($path);

        if ($text === false) {
            throw new RuntimeException("Unable to read markdown file [{$path}].");
        }

        if ((bool) config('rag.chunking.strip_html_comments', true)) {
            $text = (string) preg_replace('/<!--.*?-->/s', '', $text);
        }

        return new ExtractedDocumentText(
            content: $text,
            metadata: ['format' => 'markdown'],
        );
    }
}
