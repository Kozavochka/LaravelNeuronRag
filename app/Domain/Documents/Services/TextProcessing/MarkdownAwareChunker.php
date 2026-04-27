<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;

final class MarkdownAwareChunker
{
    public function __construct(
        private readonly ?int $sectionSplitThresholdChars = null,
        private readonly ?int $childChunkSizeChars = null,
        private readonly ?int $childOverlapChars = null,
    ) {
    }

    /**
     * @param array<string, mixed> $baseMetadata
     * @return array<int, PreparedChunk>
     */
    public function split(string $markdown, array $baseMetadata = []): array
    {
        $prepared = [];
        $globalIndex = 0;
        $sectionSplitThresholdChars = $this->sectionSplitThresholdChars
            ?? (int) config('rag.chunking.markdown.section_split_threshold_chars', (int) config('rag.chunking.size_chars', 3200));
        $childChunkSizeChars = $this->childChunkSizeChars
            ?? (int) config('rag.chunking.markdown.child_chunk_size_chars', (int) config('rag.chunking.size_chars', 3200));
        $childOverlapChars = $this->childOverlapChars
            ?? (int) config('rag.chunking.markdown.child_overlap_chars', (int) config('rag.chunking.overlap_chars', 500));
        $largeSectionChunker = new RecursiveTextChunker($childChunkSizeChars, $childOverlapChars);

        foreach ($this->parseSections($markdown) as $section) {
            $sectionPath = $section['section_path'];
            $sectionPathString = $this->formatSectionPath($sectionPath);
            $heading = $sectionPath === [] ? null : (string) end($sectionPath);
            $sectionMetadata = [
                ...$baseMetadata,
                'section_path' => $sectionPathString,
                'chunking_strategy' => 'markdown_headers',
                'heading_level' => $section['heading_level'],
            ];
            $sectionContent = $section['content'];
            $chunks = mb_strlen($sectionContent) > $sectionSplitThresholdChars
                ? $largeSectionChunker->split($sectionContent, $sectionMetadata)
                : [
                    new PreparedChunk(
                        content: $sectionContent,
                        metadata: $sectionMetadata,
                    ),
                ];

            foreach ($chunks as $chunk) {
                $prepared[] = new PreparedChunk(
                    content: $chunk->content,
                    chunkIndex: $globalIndex++,
                    metadata: $chunk->metadata,
                    heading: $heading,
                    sectionPath: $sectionPath,
                );
            }
        }

        return $prepared;
    }

    /**
     * @return list<array{content: string, section_path: list<string>, heading_level: int}>
     */
    private function parseSections(string $markdown): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $sections = [];
        $currentPath = [];
        $currentLevel = 0;
        $buffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})[ \t]+(.+?)(?:[ \t]+#+[ \t]*)?$/u', $line, $matches) === 1) {
                $this->flushSection($sections, $buffer, $currentPath, $currentLevel);
                $level = mb_strlen($matches[1]);
                $title = trim($matches[2]);

                if ($title === '') {
                    continue;
                }

                $currentPath = array_slice($currentPath, 0, max(0, $level - 1));
                $currentPath[$level - 1] = $title;
                $currentPath = array_values($currentPath);
                $currentLevel = $level;

                continue;
            }

            $buffer[] = $line;
        }

        $this->flushSection($sections, $buffer, $currentPath, $currentLevel);

        return $sections;
    }

    /**
     * @param list<array{content: string, section_path: list<string>, heading_level: int}> $sections
     * @param list<string> $buffer
     * @param list<string> $currentPath
     */
    private function flushSection(array &$sections, array &$buffer, array $currentPath, int $currentLevel): void
    {
        $content = trim(implode("\n", $buffer));
        $buffer = [];

        if ($content === '') {
            return;
        }

        $sections[] = [
            'content' => $content,
            'section_path' => $currentPath,
            'heading_level' => $currentLevel,
        ];
    }

    /**
     * @param list<string> $sectionPath
     */
    private function formatSectionPath(array $sectionPath): ?string
    {
        $sectionPath = array_values(array_filter($sectionPath, static fn (string $value): bool => $value !== ''));

        if ($sectionPath === []) {
            return null;
        }

        return implode(' / ', $sectionPath);
    }
}
