<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;

final class MarkdownAwareChunker
{
    public function __construct(
        private readonly RecursiveTextChunker $recursiveChunker,
    ) {
    }

    /**
     * @param array<string, mixed> $baseMetadata
     * @return array<int, PreparedChunk>
     */
    public function split(string $markdown, array $baseMetadata = []): array
    {
        $lines = preg_split("/\n/u", $markdown) ?: [];
        $sections = [];
        $currentHeadingPath = [];
        $currentContent = [];

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $matches) === 1) {
                if ($currentContent !== []) {
                    $sections[] = [
                        'section_path' => implode(' / ', $currentHeadingPath),
                        'content' => implode("\n", $currentContent),
                    ];
                    $currentContent = [];
                }

                $level = mb_strlen($matches[1]);
                $title = trim($matches[2]);
                $currentHeadingPath = array_slice($currentHeadingPath, 0, $level - 1);
                $currentHeadingPath[$level - 1] = $title;
            }

            $currentContent[] = $line;
        }

        if ($currentContent !== []) {
            $sections[] = [
                'section_path' => implode(' / ', $currentHeadingPath),
                'content' => implode("\n", $currentContent),
            ];
        }

        $prepared = [];
        $globalIndex = 0;

        foreach ($sections as $section) {
            $sectionPath = $section['section_path'] === ''
                ? []
                : (preg_split('/\s*\/\s*/u', $section['section_path']) ?: []);

            $chunks = $this->recursiveChunker->split(
                text: $section['content'],
                baseMetadata: [
                    ...$baseMetadata,
                    'section_path' => $section['section_path'],
                ],
            );

            foreach ($chunks as $chunk) {
                $prepared[] = new PreparedChunk(
                    content: $chunk->content,
                    chunkIndex: $globalIndex++,
                    metadata: $chunk->metadata,
                    heading: $sectionPath !== [] ? end($sectionPath) : null,
                    sectionPath: $sectionPath,
                );
            }
        }

        return $prepared;
    }
}
