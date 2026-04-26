<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\TextProcessing;

use App\Domain\Documents\DTO\PreparedChunk;
use App\Models\Document;
use App\Models\DocumentVersion;

final class ChunkMetadataEnricher
{
    /**
     * @param array<int, PreparedChunk> $chunks
     * @return array<int, PreparedChunk>
     */
    public function enrich(array $chunks, Document $document, DocumentVersion $version): array
    {
        return array_map(function (PreparedChunk $chunk) use ($document, $version): PreparedChunk {
            $metadata = [
                ...$chunk->metadata,
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'document_title' => $document->title,
                'original_filename' => $document->original_filename,
                'extension' => $document->extension,
                'source_type' => $document->source_type,
                'source_path' => $document->source_path,
                'chunk_index' => $chunk->chunkIndex,
                'content_hash' => $chunk->contentHash(),
                'char_count' => $chunk->charCount(),
                'token_estimate' => $chunk->tokenEstimate(),
            ];

            return new PreparedChunk(
                content: $chunk->content,
                chunkIndex: $chunk->chunkIndex,
                metadata: $metadata,
                heading: $chunk->heading,
                sectionPath: $chunk->sectionPath,
                pageNumber: $chunk->pageNumber,
            );
        }, $chunks);
    }
}
