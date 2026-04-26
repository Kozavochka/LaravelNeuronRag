<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\TextProcessing;

use App\Domain\Documents\TextProcessing\RecursiveTextChunker;
use PHPUnit\Framework\TestCase;

class RecursiveTextChunkerTest extends TestCase
{
    public function test_it_splits_large_text_into_multiple_chunks(): void
    {
        $chunker = new RecursiveTextChunker(chunkSize: 80, overlap: 10);
        $text = implode("\n\n", [
            str_repeat('alpha ', 10),
            str_repeat('beta ', 10),
            str_repeat('gamma ', 10),
        ]);

        $chunks = $chunker->chunk($text, 'Heading', ['Heading']);

        self::assertCount(3, $chunks);
        self::assertSame('Heading', $chunks[0]->heading);
        self::assertSame(['Heading'], $chunks[0]->sectionPath);
        self::assertLessThanOrEqual(80, mb_strlen($chunks[0]->content));
    }
}
