<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;

final class ChunkFilter
{
    /**
     * @param array<int, PreparedChunk> $chunks
     * @return array<int, PreparedChunk>
     */
    public function filter(array $chunks): array
    {
        $result = [];
        $seen = [];
        $minChunkChars = (int) config('rag.chunking.min_chunk_chars', 120);

        foreach ($chunks as $chunk) {
            $text = trim($chunk->content);

            if ($text === '' || mb_strlen($text) < $minChunkChars) {
                continue;
            }

            if ($this->isMostlyNumbersOrSymbols($text)) {
                continue;
            }

            if ($this->isUnwantedSection($text, $chunk->sectionPath)) {
                continue;
            }

            $hash = $chunk->contentHash();

            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $result[] = $chunk;
        }

        return array_values($result);
    }

    private function isMostlyNumbersOrSymbols(string $text): bool
    {
        $letters = preg_match_all('/[\p{L}]/u', $text);
        $length = max(1, mb_strlen($text));

        return ($letters / $length) < 0.25;
    }

    private function isUnwantedSection(string $text, array $sectionPath): bool
    {
        $haystack = mb_strtolower(implode(' / ', $sectionPath) . "\n" . mb_substr($text, 0, 300));
        $badMarkers = [
            'список литературы',
            'references',
            'bibliography',
            'оглавление',
            'содержание',
        ];

        foreach ($badMarkers as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }
}
