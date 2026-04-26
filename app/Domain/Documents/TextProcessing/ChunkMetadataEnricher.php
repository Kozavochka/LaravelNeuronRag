<?php

declare(strict_types=1);

namespace App\Domain\Documents\TextProcessing;

use App\Domain\Documents\DTO\ExtractedDocumentText;
use App\Domain\Documents\DTO\PreparedChunk;
use App\Models\Document;
use App\Models\DocumentVersion;

class ChunkMetadataEnricher
{
    /**
     * @param  list<PreparedChunk>  $chunks
     * @return list<PreparedChunk>
     */
    public function enrich(
        Document $document,
        DocumentVersion $version,
        array $chunks,
        ExtractedDocumentText $extractedDocumentText,
    ): array {
        $enriched = [];

        foreach (array_values($chunks) as $index => $chunk) {
            $metadata = [
                'document_id' => $document->getKey(),
                'document_version_id' => $version->getKey(),
                'document_title' => $document->title,
                'original_filename' => $document->original_filename,
                'storage_path' => $document->storage_path,
                'section_path' => implode(' > ', $chunk->sectionPath),
                'source_format' => $extractedDocumentText->metadata['source_format'] ?? null,
            ];

            $enriched[] = $chunk
                ->withMetadata(array_filter(
                    [...$extractedDocumentText->metadata, ...$metadata],
                    static fn (mixed $value): bool => $value !== null,
                ))
                ->withChunkIndex($index)
                ->withContentHash(hash('sha256', mb_strtolower(trim($chunk->content))))
                ->withTokenCount($this->estimateTokenCount($chunk->content));
        }

        return $enriched;
    }

    private function estimateTokenCount(string $content): int
    {
        $words = preg_split('/\s+/u', trim($content)) ?: [];

        return max(1, (int) ceil(count(array_filter($words)) * 1.35));
    }
}
