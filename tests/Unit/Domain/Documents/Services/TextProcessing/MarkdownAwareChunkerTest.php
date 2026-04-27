<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\Services\TextProcessing;

use App\Domain\Documents\Services\TextProcessing\MarkdownAwareChunker;
use PHPUnit\Framework\TestCase;

class MarkdownAwareChunkerTest extends TestCase
{
    public function test_it_preserves_heading_path_and_metadata(): void
    {
        $chunker = new MarkdownAwareChunker(
            sectionSplitThresholdChars: 500,
            childChunkSizeChars: 400,
            childOverlapChars: 0,
        );

        $markdown = <<<'MD'
# Root
Root paragraph with enough letters to survive filtering.

### Deep
Deep section paragraph with enough letters to survive filtering.
MD;

        $chunks = $chunker->split($markdown, ['document_id' => 10]);

        self::assertCount(2, $chunks);
        self::assertSame(['Root'], $chunks[0]->sectionPath);
        self::assertSame(['Root', 'Deep'], $chunks[1]->sectionPath);
        self::assertSame('Deep', $chunks[1]->heading);
        self::assertSame('Root / Deep', $chunks[1]->metadata['section_path']);
        self::assertSame('markdown_headers', $chunks[1]->metadata['chunking_strategy']);
        self::assertSame(3, $chunks[1]->metadata['heading_level']);
        self::assertSame(10, $chunks[1]->metadata['document_id']);
    }

    public function test_it_splits_large_sections_with_child_chunker_limits(): void
    {
        $chunker = new MarkdownAwareChunker(
            sectionSplitThresholdChars: 100,
            childChunkSizeChars: 80,
            childOverlapChars: 0,
        );

        $markdown = "# Big\n" . str_repeat('alpha ', 80);

        $chunks = $chunker->split($markdown);

        self::assertGreaterThan(1, count($chunks));
        self::assertSame('Big', $chunks[0]->heading);
        self::assertSame(['Big'], $chunks[0]->sectionPath);

        foreach ($chunks as $chunk) {
            self::assertLessThanOrEqual(80, mb_strlen($chunk->content));
            self::assertSame('markdown_headers', $chunk->metadata['chunking_strategy']);
            self::assertSame(1, $chunk->metadata['heading_level']);
        }
    }

    public function test_it_keeps_text_before_first_heading(): void
    {
        $chunker = new MarkdownAwareChunker(
            sectionSplitThresholdChars: 500,
            childChunkSizeChars: 400,
            childOverlapChars: 0,
        );

        $markdown = <<<'MD'
Intro paragraph before headings.

# Section
Body paragraph.
MD;

        $chunks = $chunker->split($markdown);

        self::assertCount(2, $chunks);
        self::assertNull($chunks[0]->heading);
        self::assertSame([], $chunks[0]->sectionPath);
        self::assertNull($chunks[0]->metadata['section_path']);
        self::assertSame(0, $chunks[0]->metadata['heading_level']);
    }

    public function test_it_skips_empty_sections_and_keeps_non_empty_following_section(): void
    {
        $chunker = new MarkdownAwareChunker(
            sectionSplitThresholdChars: 500,
            childChunkSizeChars: 400,
            childOverlapChars: 0,
        );

        $markdown = <<<'MD'
# Empty
## NonEmpty
Useful paragraph content.
MD;

        $chunks = $chunker->split($markdown);

        self::assertCount(1, $chunks);
        self::assertSame(['Empty', 'NonEmpty'], $chunks[0]->sectionPath);
        self::assertSame('NonEmpty', $chunks[0]->heading);
        self::assertSame(2, $chunks[0]->metadata['heading_level']);
    }
}
