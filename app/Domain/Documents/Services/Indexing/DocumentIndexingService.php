<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\Indexing;

use App\Domain\Documents\Contracts\MarkitdownClientInterface;
use App\Domain\Documents\DTO\ExtractedDocumentText;
use App\Domain\Documents\DTO\PreparedChunk;
use App\Domain\Documents\Services\TextExtraction\TextExtractorFactory;
use App\Domain\Documents\Services\TextProcessing\ChunkFilter;
use App\Domain\Documents\Services\TextProcessing\ChunkMetadataEnricher;
use App\Domain\Documents\Services\TextProcessing\MarkdownAwareChunker;
use App\Domain\Documents\Services\TextProcessing\RagTextSanitizer;
use App\Domain\Documents\Services\TextProcessing\RecursiveTextChunker;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Neuron\VectorStore\PgVectorStore;
use Illuminate\Support\Facades\Storage;
use NeuronAI\RAG\Document as NeuronDocument;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

final class DocumentIndexingService
{
    public function __construct(
        private readonly TextExtractorFactory $extractorFactory,
        private readonly RagTextSanitizer $sanitizer,
        private readonly RecursiveTextChunker $recursiveChunker,
        private readonly MarkdownAwareChunker $markdownChunker,
        private readonly ChunkMetadataEnricher $metadataEnricher,
        private readonly ChunkFilter $chunkFilter,
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly PgVectorStore $vectorStore,
        private readonly MarkitdownClientInterface $markitdown,
    ) {
    }

    public function index(Document $document): void
    {
        $document->update([
            'status' => 'processing',
            'error_message' => null,
        ]);

        try {
            $extracted = $this->extract($document);
            $normalizedText = $this->sanitizer->sanitize($extracted->content);
            $versionHash = hash('sha256', $normalizedText);

            $existingVersion = DocumentVersion::query()
                ->where('document_id', $document->id)
                ->where('version_hash', $versionHash)
                ->first();

            if ($existingVersion !== null) {
                $document->update([
                    'status' => 'indexed',
                    'content_hash' => $versionHash,
                ]);

                return;
            }

            $version = DocumentVersion::query()->create([
                'document_id' => $document->id,
                'version_hash' => $versionHash,
                'raw_text' => $extracted->content,
                'normalized_text' => $normalizedText,
                'metadata' => [
                    ...$extracted->metadata,
                    'embedding_model' => config('rag.embedding.model'),
                ],
                'status' => 'processing',
            ]);

            $baseMetadata = [
                'document_id' => $document->id,
                'document_version_id' => $version->id,
                'document_title' => $document->title,
                'extension' => $document->extension,
            ];

            $chunks = in_array($document->extension, ['md', 'markdown'], true)
                ? $this->markdownChunker->split($normalizedText, $baseMetadata)
                : $this->recursiveChunker->split($normalizedText, $baseMetadata);

            $chunks = $this->metadataEnricher->enrich($chunks, $document, $version);
            $chunks = $this->chunkFilter->filter($chunks);
            $documents = $this->embeddings->embedDocuments($this->toNeuronDocuments($document, $version, $chunks));

            $this->vectorStore->deleteBy('document', (string) $document->id);
            $this->vectorStore->addDocuments($documents);

            $version->update(['status' => 'indexed']);
            $document->update([
                'status' => 'indexed',
                'content_hash' => $versionHash,
            ]);
        } catch (\Throwable $throwable) {
            $document->update([
                'status' => 'failed',
                'error_message' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }

    private function extract(Document $document): ExtractedDocumentText
    {
        $disk = config('rag.documents.disk', 'local');
        $absolutePath = Storage::disk($disk)->path((string) $document->source_path);

        if (! $this->shouldConvertWithMarkitdown($document->extension)) {
            $extractor = $this->extractorFactory->for($document->extension, $document->mime_type);

            return $extractor->extract($absolutePath);
        }

        if (mb_strtolower($document->extension) === 'docx') {
            return $this->convertDocxWithFallback($document, $absolutePath);
        }

        $conversion = $this->markitdown->convert(
            absolutePath: $absolutePath,
            originalFilename: $document->original_filename,
            mimeType: $document->mime_type,
        );

        return new ExtractedDocumentText(
            content: $conversion->markdown,
            metadata: [
                'format' => 'markdown',
                'source_format' => $document->extension,
                'converted_by' => 'markitdown',
            ],
        );
    }

    private function convertDocxWithFallback(Document $document, string $absolutePath): ExtractedDocumentText
    {
        try {
            $conversion = $this->markitdown->convert(
                absolutePath: $absolutePath,
                originalFilename: $document->original_filename,
                mimeType: $document->mime_type,
            );

            return new ExtractedDocumentText(
                content: $conversion->markdown,
                metadata: [
                    'format' => 'markdown',
                    'source_format' => 'docx',
                    'converted_by' => 'markitdown',
                ],
            );
        } catch (\Throwable $throwable) {
            $extractor = $this->extractorFactory->for('docx', $document->mime_type);
            $local = $extractor->extract($absolutePath);

            return new ExtractedDocumentText(
                content: $local->content,
                metadata: [
                    ...$local->metadata,
                    'converted_by' => 'local_docx_extractor',
                    'markitdown_fallback' => true,
                    'markitdown_error' => $throwable->getMessage(),
                ],
                headings: $local->headings,
                title: $local->title,
            );
        }
    }

    private function shouldConvertWithMarkitdown(string $extension): bool
    {
        $extension = mb_strtolower($extension);

        if (in_array($extension, ['md', 'markdown'], true)) {
            return false;
        }

        if ($extension === 'docx') {
            return $this->markitdown->health()->isAvailable;
        }

        return true;
    }

    /**
     * @param array<int, PreparedChunk> $chunks
     * @return array<int, NeuronDocument>
     */
    private function toNeuronDocuments(Document $document, DocumentVersion $version, array $chunks): array
    {
        return array_map(function (PreparedChunk $chunk) use ($document, $version): NeuronDocument {
            $neuronDocument = new NeuronDocument($chunk->content);
            $neuronDocument->id = sprintf('%d:%d:%d', $document->id, $version->id, $chunk->chunkIndex);
            $neuronDocument->sourceType = 'document';
            $neuronDocument->sourceName = (string) $document->id;
            $neuronDocument->metadata = [
                ...$chunk->metadata,
                'heading' => $chunk->heading,
                'section_path' => $this->formatSectionPath($chunk->sectionPath),
                'page_number' => $chunk->pageNumber,
                'source_name' => (string) $document->id,
                'embedding_text' => $this->buildEmbeddingText($chunk),
            ];
            $neuronDocument->content = $this->buildEmbeddingText($chunk);
            $neuronDocument->metadata['raw_content'] = $chunk->content;

            return $neuronDocument;
        }, $chunks);
    }

    private function buildEmbeddingText(PreparedChunk $chunk): string
    {
        $prefix = [];

        if (($chunk->metadata['document_title'] ?? null) !== null) {
            $prefix[] = 'Документ: ' . $chunk->metadata['document_title'];
        }

        $sectionPath = $this->formatSectionPath($chunk->sectionPath);

        if ($sectionPath !== null && $sectionPath !== '') {
            $prefix[] = 'Раздел: ' . $sectionPath;
        }

        return $prefix === []
            ? $chunk->content
            : implode("\n", $prefix) . "\n\n" . $chunk->content;
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
