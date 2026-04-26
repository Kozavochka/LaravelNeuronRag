<?php

declare(strict_types=1);

namespace App\Domain\Documents\Indexing;

use App\Domain\Documents\Contracts\DocumentVectorStoreInterface;
use App\Domain\Documents\DTO\IndexingResult;
use App\Domain\Documents\Enums\DocumentStatus;
use App\Domain\Documents\Enums\DocumentVersionStatus;
use App\Domain\Documents\TextExtraction\TextExtractorFactory;
use App\Domain\Documents\TextProcessing\ChunkFilter;
use App\Domain\Documents\TextProcessing\ChunkMetadataEnricher;
use App\Domain\Documents\TextProcessing\MarkdownAwareChunker;
use App\Domain\Documents\TextProcessing\RagTextSanitizer;
use App\Domain\Documents\TextProcessing\RecursiveTextChunker;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DocumentIndexingService
{
    public function __construct(
        private readonly TextExtractorFactory $extractorFactory,
        private readonly RagTextSanitizer $sanitizer,
        private readonly RecursiveTextChunker $recursiveTextChunker,
        private readonly MarkdownAwareChunker $markdownAwareChunker,
        private readonly ChunkMetadataEnricher $chunkMetadataEnricher,
        private readonly ChunkFilter $chunkFilter,
        private readonly DocumentVectorStoreInterface $vectorStore,
    ) {
    }

    public function index(Document $document, bool $force = false): IndexingResult
    {
        $version = null;

        try {
            $document->forceFill([
                'status' => DocumentStatus::Processing,
                'index_error' => null,
            ])->save();

            $path = Storage::disk($document->storage_disk)->path($document->storage_path);
            $extractor = $this->extractorFactory->for(
                $document->extension ?? pathinfo($path, PATHINFO_EXTENSION),
                $document->mime_type,
            );
            $extracted = $extractor->extract($path);
            $sanitizedText = $this->sanitizer->sanitize($extracted->content);
            $contentHash = hash('sha256', $sanitizedText);
            $latestVersion = $document->latestVersion()->first();

            if (! $force && $latestVersion !== null && $latestVersion->content_hash === $contentHash) {
                $document->forceFill([
                    'status' => DocumentStatus::Indexed,
                    'last_indexed_at' => now(),
                    'index_error' => null,
                ])->save();

                return new IndexingResult(
                    skipped: true,
                    chunkCount: (int) $latestVersion->chunk_count,
                    version: $latestVersion,
                    contentHash: $contentHash,
                );
            }

            $version = $this->createVersion($document, $contentHash, $path);

            $chunks = $this->buildChunks(
                extension: $document->extension ?? '',
                content: $sanitizedText,
            );
            $chunks = $this->chunkMetadataEnricher->enrich($document, $version, $chunks, $extracted);
            $chunks = $this->chunkFilter->filter($chunks);

            if ($chunks === []) {
                throw new \RuntimeException('Document indexing produced no chunks.');
            }

            $this->vectorStore->replaceDocumentVersion($document, $version, $chunks);

            $version->forceFill([
                'status' => DocumentVersionStatus::Indexed,
                'extracted_characters' => mb_strlen($sanitizedText),
                'chunk_count' => count($chunks),
                'indexed_at' => now(),
                'error_message' => null,
            ])->save();

            $document->forceFill([
                'status' => DocumentStatus::Indexed,
                'latest_version_id' => $version->getKey(),
                'last_indexed_at' => now(),
                'index_error' => null,
                'title' => $document->title ?: ($extracted->title ?? $document->title),
            ])->save();

            return new IndexingResult(
                skipped: false,
                chunkCount: count($chunks),
                version: $version->fresh(),
                contentHash: $contentHash,
            );
        } catch (Throwable $throwable) {
            if ($version instanceof DocumentVersion) {
                $version->forceFill([
                    'status' => DocumentVersionStatus::Failed,
                    'error_message' => $throwable->getMessage(),
                ])->save();
            }

            $document->forceFill([
                'status' => DocumentStatus::Failed,
                'index_error' => $throwable->getMessage(),
            ])->save();

            throw $throwable;
        }
    }

    private function createVersion(Document $document, string $contentHash, string $path): DocumentVersion
    {
        $nextVersion = ((int) $document->versions()->max('version')) + 1;

        return $document->versions()->create([
            'version' => $nextVersion,
            'content_hash' => $contentHash,
            'status' => DocumentVersionStatus::Processing,
            'source_size_bytes' => filesize($path) ?: null,
            'started_at' => now(),
        ]);
    }

    private function buildChunks(string $extension, string $content): array
    {
        $extension = strtolower($extension);

        if (in_array($extension, ['md', 'markdown'], true)) {
            return $this->markdownAwareChunker->chunk($content);
        }

        return $this->recursiveTextChunker->chunk($content);
    }
}
