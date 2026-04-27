<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rag_queries')) {
            Schema::table('rag_queries', function (Blueprint $table): void {
                $table->index(['created_at', 'llm_model'], 'rag_queries_created_at_llm_model_idx');
            });
        }

        if (Schema::hasTable('rag_query_chunks')) {
            Schema::table('rag_query_chunks', function (Blueprint $table): void {
                $table->index(['rag_query_id', 'rank'], 'rag_query_chunks_query_id_rank_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rag_queries')) {
            Schema::table('rag_queries', function (Blueprint $table): void {
                $table->dropIndex('rag_queries_created_at_llm_model_idx');
            });
        }

        if (Schema::hasTable('rag_query_chunks')) {
            Schema::table('rag_query_chunks', function (Blueprint $table): void {
                $table->dropIndex('rag_query_chunks_query_id_rank_idx');
            });
        }
    }
};
