<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\TextProcessing;

use App\Domain\Documents\TextProcessing\MarkdownAwareChunker;
use App\Domain\Documents\TextProcessing\RecursiveTextChunker;
use PHPUnit\Framework\TestCase;

class MarkdownAwareChunkerTest extends TestCase
{
    public function test_it_preserves_heading_path_for_markdown_sections(): void
    {
        $chunker = new MarkdownAwareChunker(
            new RecursiveTextChunker(chunkSize: 500, overlap: 0),
        );

        $markdown = <<<'MD'
# Root
Intro paragraph for root.

## Child
Child paragraph with enough words to become a valid chunk for indexing.
MD;

        $chunks = $chunker->chunk($markdown);

        self::assertCount(2, $chunks);
        self::assertSame(['Root'], $chunks[0]->sectionPath);
        self::assertSame(['Root', 'Child'], $chunks[1]->sectionPath);
        self::assertSame('Child', $chunks[1]->heading);
    }
}
