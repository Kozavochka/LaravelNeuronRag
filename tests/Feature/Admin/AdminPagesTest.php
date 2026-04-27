<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

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
            'metadata' => json_encode(['document_id' => 1]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('/admin/rag-queries/' . $queryId)
            ->assertOk()
            ->assertSee('Telemetry');
    }
}
