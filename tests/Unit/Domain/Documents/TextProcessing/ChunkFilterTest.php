<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;
use App\Domain\Documents\TextProcessing\ChunkFilter;
use PHPUnit\Framework\TestCase;

class ChunkFilterTest extends TestCase
{
    public function test_it_removes_short_and_duplicate_chunks(): void
    {
        $filter = new ChunkFilter(minimumLength: 20);
        $chunks = [
            new PreparedChunk(content: 'short'),
            new PreparedChunk(content: 'This is a sufficiently large chunk for indexing.'),
            new PreparedChunk(content: 'This is a sufficiently large chunk for indexing.'),
            new PreparedChunk(content: 'Another sufficiently large chunk that should survive.'),
        ];

        $filtered = $filter->filter($chunks);

        self::assertCount(2, $filtered);
        self::assertSame(0, $filtered[0]->chunkIndex);
        self::assertSame(1, $filtered[1]->chunkIndex);
    }
}
