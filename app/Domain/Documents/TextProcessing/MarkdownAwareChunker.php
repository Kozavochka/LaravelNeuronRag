<?php

declare(strict_types=1);

namespace App\Domain\Documents\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;

class MarkdownAwareChunker
{
    public function __construct(
        private readonly RecursiveTextChunker $chunker = new RecursiveTextChunker(),
    ) {
    }

    /**
     * @return list<PreparedChunk>
     */
    public function chunk(string $text): array
    {
        $lines = preg_split("/\n/u", $text) ?: [];
        $sections = [];
        $path = [];
        $buffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $match) === 1) {
                if ($buffer !== []) {
                    $sections[] = [
                        'path' => $path,
                        'content' => trim(implode("\n", $buffer)),
                    ];
                }

                $level = strlen($match[1]);
                $heading = trim($match[2]);
                $path = array_slice($path, 0, max(0, $level - 1));
                $path[$level - 1] = $heading;
                $buffer = [$line];

                continue;
            }

            $buffer[] = $line;
        }

        if ($buffer !== []) {
            $sections[] = [
                'path' => $path,
                'content' => trim(implode("\n", $buffer)),
            ];
        }

        if ($sections === []) {
            return $this->chunker->chunk($text);
        }

        $chunks = [];

        foreach ($sections as $section) {
            $sectionPath = array_values(array_filter($section['path'] ?? []));
            $heading = $sectionPath !== [] ? end($sectionPath) : null;

            foreach ($this->chunker->chunk($section['content'], $heading ?: null, $sectionPath) as $chunk) {
                $chunks[] = $chunk;
            }
        }

        return $chunks;
    }
}
