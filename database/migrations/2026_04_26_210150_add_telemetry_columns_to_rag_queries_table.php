<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rag_queries')) {
            return;
        }

        Schema::table('rag_queries', function (Blueprint $table): void {
            if (! Schema::hasColumn('rag_queries', 'embedding_ms')) {
                $table->unsignedInteger('embedding_ms')->nullable()->after('top_k');
            }

            if (! Schema::hasColumn('rag_queries', 'vector_search_ms')) {
                $table->unsignedInteger('vector_search_ms')->nullable()->after('embedding_ms');
            }

            if (! Schema::hasColumn('rag_queries', 'rerank_ms')) {
                $table->unsignedInteger('rerank_ms')->nullable()->after('vector_search_ms');
            }

            if (! Schema::hasColumn('rag_queries', 'prompt_build_ms')) {
                $table->unsignedInteger('prompt_build_ms')->nullable()->after('rerank_ms');
            }

            if (! Schema::hasColumn('rag_queries', 'llm_ms')) {
                $table->unsignedInteger('llm_ms')->nullable()->after('prompt_build_ms');
            }

            if (! Schema::hasColumn('rag_queries', 'total_ms')) {
                $table->unsignedInteger('total_ms')->nullable()->after('llm_ms');
            }

            if (! Schema::hasColumn('rag_queries', 'prompt_tokens')) {
                $table->unsignedInteger('prompt_tokens')->nullable()->after('total_ms');
            }

            if (! Schema::hasColumn('rag_queries', 'completion_tokens')) {
                $table->unsignedInteger('completion_tokens')->nullable()->after('prompt_tokens');
            }

            if (! Schema::hasColumn('rag_queries', 'total_tokens')) {
                $table->unsignedInteger('total_tokens')->nullable()->after('completion_tokens');
            }

            if (! Schema::hasColumn('rag_queries', 'estimated_cost_usd')) {
                $table->decimal('estimated_cost_usd', 12, 8)->nullable()->after('total_tokens');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('rag_queries')) {
            return;
        }

        Schema::table('rag_queries', function (Blueprint $table): void {
            $columns = [
                'embedding_ms',
                'vector_search_ms',
                'rerank_ms',
                'prompt_build_ms',
                'llm_ms',
                'total_ms',
                'prompt_tokens',
                'completion_tokens',
                'total_tokens',
                'estimated_cost_usd',
            ];

            $existing = array_values(array_filter(
                $columns,
                static fn (string $column): bool => Schema::hasColumn('rag_queries', $column)
            ));

            if ($existing !== []) {
                $table->dropColumn($existing);
            }
        });
    }
};
