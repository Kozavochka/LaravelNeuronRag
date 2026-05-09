<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\Services\Indexing;

use App\Domain\Documents\DTO\PreparedChunk;
use App\Domain\Documents\DTO\MarkitdownHealthResult;
use App\Domain\Documents\Contracts\MarkitdownClientInterface;
use App\Domain\Documents\Services\Indexing\DocumentIndexingService;
use App\Domain\Documents\Services\TextExtraction\TextExtractorFactory;
use App\Domain\Documents\Services\TextProcessing\ChunkFilter;
use App\Domain\Documents\Services\TextProcessing\ChunkMetadataEnricher;
use App\Domain\Documents\Services\TextProcessing\MarkdownAwareChunker;
use App\Domain\Documents\Services\TextProcessing\RagTextSanitizer;
use App\Domain\Documents\Services\TextProcessing\RecursiveTextChunker;
use App\Domain\Rag\Services\Telemetry\RagQueryTelemetry;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Neuron\VectorStore\PgVectorStore;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use PHPUnit\Framework\TestCase;

class DocumentIndexingServiceTest extends TestCase
{
    public function test_it_builds_embedding_text_with_context_and_preserves_raw_content(): void
    {
        $service = new DocumentIndexingService(
            extractorFactory: new TextExtractorFactory([]),
            sanitizer: new RagTextSanitizer(),
            recursiveChunker: new RecursiveTextChunker(),
            markdownChunker: new MarkdownAwareChunker(),
            metadataEnricher: new ChunkMetadataEnricher(),
            chunkFilter: new ChunkFilter(),
            embeddings: $this->createMock(EmbeddingsProviderInterface::class),
            vectorStore: new PgVectorStore(telemetry: new RagQueryTelemetry()),
            markitdown: $this->createMock(MarkitdownClientInterface::class),
        );

        $document = new Document();
        $document->id = 7;
        $document->title = 'Spec';

        $version = new DocumentVersion();
        $version->id = 3;

        $chunks = [
            new PreparedChunk(
                content: 'Raw chunk body',
                chunkIndex: 0,
                sectionPath: ['RAG', 'Chunking'],
                metadata: ['document_title' => 'Spec'],
            ),
        ];

        $method = new \ReflectionMethod($service, 'toNeuronDocuments');
        $method->setAccessible(true);

        /** @var array<int, object> $result */
        $result = $method->invoke($service, $document, $version, $chunks);

        self::assertCount(1, $result);
        self::assertStringContainsString('Документ: Spec', $result[0]->content);
        self::assertStringContainsString('Раздел: RAG / Chunking', $result[0]->content);
        self::assertStringContainsString('Raw chunk body', $result[0]->content);
        self::assertSame('Raw chunk body', $result[0]->metadata['raw_content']);
        self::assertSame('RAG / Chunking', $result[0]->metadata['section_path']);
        self::assertSame($result[0]->content, $result[0]->metadata['embedding_text']);
    }

    public function test_it_uses_markitdown_for_docx_when_service_is_available(): void
    {
        $markitdown = $this->createMock(MarkitdownClientInterface::class);
        $markitdown->method('health')->willReturn(new MarkitdownHealthResult(
            isAvailable: true,
            status: 'ok',
        ));

        $service = new DocumentIndexingService(
            extractorFactory: new TextExtractorFactory([]),
            sanitizer: new RagTextSanitizer(),
            recursiveChunker: new RecursiveTextChunker(),
            markdownChunker: new MarkdownAwareChunker(),
            metadataEnricher: new ChunkMetadataEnricher(),
            chunkFilter: new ChunkFilter(),
            embeddings: $this->createMock(EmbeddingsProviderInterface::class),
            vectorStore: new PgVectorStore(telemetry: new RagQueryTelemetry()),
            markitdown: $markitdown,
        );

        $method = new \ReflectionMethod($service, 'shouldConvertWithMarkitdown');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($service, 'docx'));
    }

    public function test_it_falls_back_to_local_extractor_for_docx_when_service_is_unavailable(): void
    {
        $markitdown = $this->createMock(MarkitdownClientInterface::class);
        $markitdown->method('health')->willReturn(new MarkitdownHealthResult(
            isAvailable: false,
            status: 'down',
        ));

        $service = new DocumentIndexingService(
            extractorFactory: new TextExtractorFactory([]),
            sanitizer: new RagTextSanitizer(),
            recursiveChunker: new RecursiveTextChunker(),
            markdownChunker: new MarkdownAwareChunker(),
            metadataEnricher: new ChunkMetadataEnricher(),
            chunkFilter: new ChunkFilter(),
            embeddings: $this->createMock(EmbeddingsProviderInterface::class),
            vectorStore: new PgVectorStore(telemetry: new RagQueryTelemetry()),
            markitdown: $markitdown,
        );

        $method = new \ReflectionMethod($service, 'shouldConvertWithMarkitdown');
        $method->setAccessible(true);

        self::assertFalse($method->invoke($service, 'docx'));
        self::assertFalse($method->invoke($service, 'md'));
    }
}
