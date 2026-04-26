<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('document_chunks')) {
            return;
        }

        Schema::create('document_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_version_id')->constrained()->cascadeOnDelete();
            $table->integer('chunk_index');
            $table->longText('content');
            $table->string('content_hash', 64);
            $table->integer('char_count')->nullable();
            $table->integer('token_estimate')->nullable();
            $table->string('heading')->nullable();
            $table->string('section_path')->nullable();
            $table->integer('page_number')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['document_id', 'is_active']);
            $table->index(['document_version_id', 'is_active']);
            $table->index('content_hash');
        });

        if (DB::getDriverName() === 'pgsql') {
            $dimensions = (int) config('rag.embedding.dimensions', 1024);
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            DB::statement(sprintf(
                'ALTER TABLE document_chunks ADD COLUMN embedding vector(%d)',
                $dimensions
            ));
            DB::statement(
                'CREATE INDEX document_chunks_embedding_hnsw_idx ON document_chunks USING hnsw (embedding vector_cosine_ops)'
            );
        } else {
            Schema::table('document_chunks', function (Blueprint $table): void {
                $table->longText('embedding')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
