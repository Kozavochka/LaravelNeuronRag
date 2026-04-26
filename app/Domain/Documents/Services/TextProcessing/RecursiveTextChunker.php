<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;

final class RecursiveTextChunker
{
    public function __construct(
        private readonly int $chunkSizeChars = 3200,
        private readonly int $overlapChars = 500,
    ) {
    }

    /**
     * @param array<string, mixed> $baseMetadata
     * @return array<int, PreparedChunk>
     */
    public function split(string $text, array $baseMetadata = []): array
    {
        $paragraphs = preg_split("/\n{2,}/u", $text) ?: [];
        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);

            if ($paragraph === '') {
                continue;
            }

            $candidate = $buffer === '' ? $paragraph : $buffer . "\n\n" . $paragraph;

            if (mb_strlen($candidate) <= $this->chunkSizeChars) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
            }

            if (mb_strlen($paragraph) > $this->chunkSizeChars) {
                array_push($chunks, ...$this->splitLargeText($paragraph));
                $buffer = '';
                continue;
            }

            $buffer = $paragraph;
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        $chunks = $this->applyOverlap($chunks);

        return array_map(
            fn (string $content, int $index): PreparedChunk => new PreparedChunk(
                content: $content,
                chunkIndex: $index,
                metadata: $baseMetadata,
            ),
            $chunks,
            array_keys($chunks),
        );
    }

    /**
     * @return array<int, string>
     */
    private function splitLargeText(string $text): array
    {
        $parts = [];
        $length = mb_strlen($text);
        $start = 0;

        while ($start < $length) {
            $parts[] = mb_substr($text, $start, $this->chunkSizeChars);
            $start += max(1, $this->chunkSizeChars - $this->overlapChars);
        }

        return $parts;
    }

    /**
     * @param array<int, string> $chunks
     * @return array<int, string>
     */
    private function applyOverlap(array $chunks): array
    {
        $result = [];

        foreach ($chunks as $index => $chunk) {
            if ($index === 0 || $this->overlapChars <= 0) {
                $result[] = trim($chunk);
                continue;
            }

            $previous = $chunks[$index - 1];
            $overlap = mb_substr($previous, max(0, mb_strlen($previous) - $this->overlapChars));
            $result[] = trim($overlap . "\n\n" . $chunk);
        }

        return $result;
    }
}
