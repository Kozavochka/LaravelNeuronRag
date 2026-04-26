<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\TextExtraction;

use App\Domain\Documents\TextExtraction\MarkdownTextExtractor;
use PHPUnit\Framework\TestCase;

class MarkdownTextExtractorTest extends TestCase
{
    public function test_it_extracts_markdown_text_and_headings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'markdown-test-');
        file_put_contents($path, "# Title\n\n<!-- hidden -->\nBody text\n\n## Section\nMore body");

        try {
            $extractor = new MarkdownTextExtractor();
            $extracted = $extractor->extract($path);

            self::assertSame('Title', $extracted->title);
            self::assertSame(['Title', 'Section'], $extracted->headings);
            self::assertStringNotContainsString('hidden', $extracted->content);
            self::assertStringContainsString('Body text', $extracted->content);
        } finally {
            @unlink($path);
        }
    }
}
