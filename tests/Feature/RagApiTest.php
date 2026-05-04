<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Documents\Jobs\ProcessDocumentJob;
use App\Domain\Rag\DTO\RagChatResult;
use App\Domain\Rag\DTO\RagChatSource;
use App\Domain\Rag\Services\RagChatRuntime;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RagApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_index_returns_paginated_payload(): void
    {
        $response = $this->getJson('/api/rag/documents');

        $response
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('per_page', (int) config('rag.http.pagination.per_page'));
    }

    public function test_document_upload_requires_a_supported_file(): void
    {
        $response = $this->postJson('/api/rag/documents', [
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_document_upload_accepts_markdown_files_and_dispatches_indexing(): void
    {
        Queue::fake();

        $response = $this->post('/api/rag/documents', [
            'file' => UploadedFile::fake()->createWithContent('notes.md', '# Title'),
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.original_filename', 'notes.md')
            ->assertJsonPath('data.extension', 'md');

        $this->assertDatabaseCount('documents', 1);
        Queue::assertPushed(ProcessDocumentJob::class);
    }

    public function test_document_show_returns_persisted_document(): void
    {
        $document = Document::query()->create([
            'title' => 'Architecture',
            'original_filename' => 'architecture.md',
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'source_type' => 'upload',
            'source_path' => 'rag/documents/architecture.md',
            'status' => 'uploaded',
        ]);

        $response = $this->getJson("/api/rag/documents/{$document->id}");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.title', 'Architecture')
            ->assertJsonPath('data.status', 'uploaded');
    }

    public function test_document_reindex_dispatches_job(): void
    {
        Queue::fake();

        $document = Document::query()->create([
            'title' => 'Architecture',
            'original_filename' => 'architecture.md',
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'source_type' => 'upload',
            'source_path' => 'rag/documents/architecture.md',
            'status' => 'indexed',
        ]);

        $response = $this->postJson("/api/rag/documents/{$document->id}/reindex");

        $response
            ->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.status', 'queued');

        Queue::assertPushed(ProcessDocumentJob::class);
    }

    public function test_chat_endpoint_validates_question(): void
    {
        $response = $this->postJson('/api/rag/chat', []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['question']);
    }

    public function test_chat_endpoint_returns_runtime_result(): void
    {
        $document = Document::query()->create([
            'title' => 'Architecture',
            'original_filename' => 'architecture.md',
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'source_type' => 'upload',
            'source_path' => 'rag/documents/architecture.md',
            'status' => 'indexed',
        ]);

        $this->mock(RagChatRuntime::class, function ($mock) use ($document): void {
            $mock->shouldReceive('answer')
                ->once()
                ->withArgs(function (string $question, ?int $documentId, ?int $userId, array $filters, ?int $topK, ?string $retrievalMode) use ($document): bool {
                    return $question === 'What is Neuron?'
                        && $documentId === $document->id
                        && $userId === null
                        && $filters === []
                        && $topK === null
                        && $retrievalMode === 'hybrid';
                })
                ->andReturn(new RagChatResult(
                    answer: 'Neuron is a PHP AI framework.',
                    sources: [
                        new RagChatSource(
                            chunkId: 1,
                            documentId: $document->id,
                            content: 'Neuron is a PHP AI framework.',
                            score: 0.98,
                            distance: 0.02,
                            rerankScore: 1.12,
                            rank: 1,
                            metadata: ['document_title' => 'Architecture'],
                        ),
                    ],
                    queryId: 123,
                    rerankMs: 9,
                ));
        });

        $response = $this->postJson('/api/rag/chat', [
            'question' => 'What is Neuron?',
            'document_id' => $document->id,
            'retrieval_mode' => 'hybrid',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.answer', 'Neuron is a PHP AI framework.')
            ->assertJsonPath('data.query_id', 123)
            ->assertJsonPath('data.rerank_ms', 9)
            ->assertJsonPath('data.retrieval_mode', 'hybrid')
            ->assertJsonPath('data.sources.0.rerank_score', 1.12)
            ->assertJsonPath('data.sources.0.document_id', $document->id);
    }

    public function test_chat_endpoint_validates_retrieval_mode(): void
    {
        $response = $this->postJson('/api/rag/chat', [
            'question' => 'What is Neuron?',
            'retrieval_mode' => 'broken',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['retrieval_mode']);
    }
}
