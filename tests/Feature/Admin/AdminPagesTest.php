<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Documents\Contracts\MarkitdownClientInterface;
use App\Domain\Documents\DTO\MarkitdownHealthResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('rag:markitdown:health');
    }

    public function test_dashboard_renders(): void
    {
        $response = $this->get('/admin');

        $response->assertOk()->assertSee('Queries');
    }

    public function test_documents_pages_render(): void
    {
        $documentId = DB::table('documents')->insertGetId([
            'title' => 'Architecture',
            'original_filename' => 'architecture.md',
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'source_type' => 'upload',
            'source_path' => 'rag/documents/architecture.md',
            'status' => 'indexed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/admin/documents');
        $response->assertOk()->assertSee('Architecture');

        $this->get("/admin/documents/{$documentId}")
            ->assertOk()
            ->assertSee('Architecture');
    }

    public function test_rag_queries_index_page_renders_with_filters(): void
    {
        $documentId = DB::table('documents')->insertGetId([
            'title' => 'Architecture',
            'original_filename' => 'architecture.md',
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'source_type' => 'upload',
            'source_path' => 'rag/documents/architecture.md',
            'status' => 'indexed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queryId = DB::table('rag_queries')->insertGetId([
            'question' => 'What is pgvector?',
            'answer' => 'Vector extension for PostgreSQL.',
            'llm_provider' => 'openrouter',
            'llm_model' => 'openrouter/auto',
            'embedding_model' => 'bge-m3',
            'top_k' => 2,
            'total_ms' => 120,
            'total_tokens' => 150,
            'estimated_cost_usd' => 0.00050000,
            'metadata' => json_encode(['document_id' => $documentId]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get('/admin/rag-queries?document_id=' . $documentId . '&sort=total_ms&dir=desc');
        $response->assertOk()->assertSee('What is pgvector?');
    }

    public function test_rag_query_show_page_renders(): void
    {
        $documentId = DB::table('documents')->insertGetId([
            'title' => 'Architecture',
            'original_filename' => 'architecture.md',
            'mime_type' => 'text/markdown',
            'extension' => 'md',
            'source_type' => 'upload',
            'source_path' => 'rag/documents/architecture.md',
            'status' => 'indexed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $queryId = DB::table('rag_queries')->insertGetId([
            'question' => 'What is pgvector?',
            'answer' => 'Vector extension for PostgreSQL.',
            'llm_provider' => 'openrouter',
            'llm_model' => 'openrouter/auto',
            'embedding_model' => 'bge-m3',
            'top_k' => 2,
            'keyword_search_ms' => 5,
            'hybrid_merge_ms' => 2,
            'total_ms' => 120,
            'total_tokens' => 150,
            'estimated_cost_usd' => 0.00050000,
            'metadata' => json_encode([
                'document_id' => $documentId,
                'retrieval' => [
                    'mode' => 'hybrid',
                    'resolved_mode' => 'hybrid',
                    'vector_candidates' => 30,
                    'keyword_candidates' => 30,
                    'final_top_k' => 8,
                    'ts_dictionary' => 'simple',
                ],
                'sources' => [
                    [
                        'chunk_id' => 1,
                        'document_id' => $documentId,
                        'rank' => 1,
                        'score' => 0.91,
                        'vector_score' => 0.88,
                        'keyword_score' => 0.66,
                        'retrieval_source' => 'hybrid',
                        'vector_rank' => 1,
                        'keyword_rank' => 2,
                    ],
                ],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = DB::table('document_versions')->insertGetId([
            'document_id' => $documentId,
            'version_hash' => str_repeat('c', 64),
            'raw_text' => 'pgvector hybrid search',
            'normalized_text' => 'pgvector hybrid search',
            'status' => 'indexed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $chunkId = DB::table('document_chunks')->insertGetId([
            'document_id' => $documentId,
            'document_version_id' => $versionId,
            'chunk_index' => 0,
            'content' => 'pgvector hybrid search',
            'content_hash' => str_repeat('d', 64),
            'char_count' => 22,
            'token_estimate' => 3,
            'metadata' => json_encode(['document_title' => 'Architecture']),
            'is_active' => true,
            'embedding' => json_encode([0.1, 0.2]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('rag_query_chunks')->insert([
            'rag_query_id' => $queryId,
            'document_chunk_id' => $chunkId,
            'distance' => 0.02,
            'score' => 0.91,
            'vector_score' => 0.88,
            'keyword_score' => 0.66,
            'retrieval_source' => 'hybrid',
            'vector_rank' => 1,
            'keyword_rank' => 2,
            'rank' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/admin/rag-queries/' . $queryId)
            ->assertOk()
            ->assertSee('Telemetry')
            ->assertSee('retrieval_mode: hybrid')
            ->assertSee('keyword_score')
            ->assertSee('hybrid');
    }

    public function test_markitdown_integration_page_renders(): void
    {
        $this->mock(MarkitdownClientInterface::class, function ($mock): void {
            $mock->shouldReceive('health')
                ->andReturn(new MarkitdownHealthResult(
                    isAvailable: true,
                    status: 'ok',
                ));
        });

        DB::table('integration_events')->insert([
            'integration' => 'markitdown',
            'event_type' => 'health',
            'status_code' => 200,
            'latency_ms' => 12,
            'message' => 'Markitdown health check passed.',
            'context' => json_encode(['status' => 'ok']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/admin/integrations/markitdown')
            ->assertOk()
            ->assertSee('MarkItDown')
            ->assertSee('Recent events');
    }
}
