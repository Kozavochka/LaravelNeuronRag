<?php

declare(strict_types=1);

namespace App\Domain\Documents\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;

class ChunkFilter
{
    public function __construct(
        private readonly int $minimumLength = 40,
    ) {
    }

    /**
     * @param  list<PreparedChunk>  $chunks
     * @return list<PreparedChunk>
     */
    public function filter(array $chunks): array
    {
        $unique = [];
        $filtered = [];

        foreach ($chunks as $chunk) {
            $normalized = preg_replace('/\s+/u', ' ', trim($chunk->content)) ?? trim($chunk->content);

            if ($normalized === '' || mb_strlen($normalized) < $this->minimumLength) {
                continue;
            }

            $hash = $chunk->contentHash ?? hash('sha256', mb_strtolower($normalized));

            if (isset($unique[$hash])) {
                continue;
            }

            $unique[$hash] = true;
            $filtered[] = $chunk->withContentHash($hash);
        }

        return array_values(array_map(
            static fn (PreparedChunk $chunk, int $index): PreparedChunk => $chunk->withChunkIndex($index),
            $filtered,
            array_keys($filtered),
        ));
    }
}
