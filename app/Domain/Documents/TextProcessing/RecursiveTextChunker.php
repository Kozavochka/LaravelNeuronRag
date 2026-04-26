<?php

declare(strict_types=1);

namespace App\Domain\Documents\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;

class RecursiveTextChunker
{
    /**
     * @param  list<string>  $separators
     */
    public function __construct(
        private readonly int $chunkSize = 1200,
        private readonly int $overlap = 200,
        private readonly array $separators = ["\n\n", "\n", '. ', ' '],
    ) {
    }

    /**
     * @return list<PreparedChunk>
     */
    public function chunk(string $text, ?string $heading = null, array $sectionPath = []): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $segments = $this->splitToSegments($text);
        $chunks = [];
        $buffer = '';
        $bufferStart = 0;
        $cursor = 0;

        foreach ($segments as $segment) {
            $segment = trim($segment);

            if ($segment === '') {
                continue;
            }

            if (mb_strlen($segment) > $this->chunkSize) {
                if ($buffer !== '') {
                    $chunks[] = $this->makeChunk($buffer, $heading, $sectionPath, $bufferStart);
                    $buffer = '';
                }

                foreach ($this->splitLongSegment($segment) as $subChunk) {
                    $chunks[] = $this->makeChunk($subChunk, $heading, $sectionPath, $cursor);
                    $cursor += mb_strlen($subChunk);
                }

                $bufferStart = $cursor;

                continue;
            }

            $candidate = $buffer === '' ? $segment : $buffer."\n\n".$segment;

            if (mb_strlen($candidate) <= $this->chunkSize) {
                if ($buffer === '') {
                    $bufferStart = $cursor;
                }

                $buffer = $candidate;
                $cursor += mb_strlen($segment) + 2;

                continue;
            }

            $chunks[] = $this->makeChunk($buffer, $heading, $sectionPath, $bufferStart);
            $buffer = $this->overlapText($buffer)."\n\n".$segment;
            $buffer = trim($buffer);
            $bufferStart = max(0, $cursor - mb_strlen($this->overlapText($buffer)));
            $cursor += mb_strlen($segment) + 2;
        }

        if ($buffer !== '') {
            $chunks[] = $this->makeChunk($buffer, $heading, $sectionPath, $bufferStart);
        }

        return array_values(array_filter(
            $chunks,
            static fn (PreparedChunk $chunk): bool => trim($chunk->content) !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function splitToSegments(string $text): array
    {
        $paragraphs = preg_split("/\n{2,}/u", $text) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), $paragraphs),
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function splitLongSegment(string $text): array
    {
        $chunks = [];
        $offset = 0;
        $length = mb_strlen($text);

        while ($offset < $length) {
            $slice = mb_substr($text, $offset, $this->chunkSize);
            $actualLength = mb_strlen($slice);

            if ($offset + $actualLength < $length) {
                $breakpoint = max(
                    mb_strrpos($slice, "\n"),
                    mb_strrpos($slice, '.'),
                    mb_strrpos($slice, ' '),
                );

                if (is_int($breakpoint) && $breakpoint > (int) ($this->chunkSize * 0.5)) {
                    $slice = trim(mb_substr($slice, 0, $breakpoint + 1));
                    $actualLength = mb_strlen($slice);
                }
            }

            $chunks[] = trim($slice);
            $offset += max(1, $actualLength - $this->overlap);
        }

        return $chunks;
    }

    private function overlapText(string $text): string
    {
        if ($this->overlap <= 0) {
            return '';
        }

        return trim(mb_substr($text, max(0, mb_strlen($text) - $this->overlap)));
    }

    /**
     * @param  list<string>  $sectionPath
     */
    private function makeChunk(string $content, ?string $heading, array $sectionPath, int $charStart): PreparedChunk
    {
        $content = trim($content);
        $charEnd = $charStart + mb_strlen($content);

        return new PreparedChunk(
            content: $content,
            heading: $heading,
            sectionPath: $sectionPath,
            charStart: $charStart,
            charEnd: $charEnd,
        );
    }
}
