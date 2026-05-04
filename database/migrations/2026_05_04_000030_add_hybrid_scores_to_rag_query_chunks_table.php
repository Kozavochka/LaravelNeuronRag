<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rag_query_chunks')) {
            return;
        }

        Schema::table('rag_query_chunks', function (Blueprint $table): void {
            if (! Schema::hasColumn('rag_query_chunks', 'vector_score')) {
                $table->float('vector_score')->nullable()->after('score');
            }

            if (! Schema::hasColumn('rag_query_chunks', 'keyword_score')) {
                $table->float('keyword_score')->nullable()->after('vector_score');
            }

            if (! Schema::hasColumn('rag_query_chunks', 'retrieval_source')) {
                $table->string('retrieval_source')->nullable()->after('keyword_score');
            }

            if (! Schema::hasColumn('rag_query_chunks', 'vector_rank')) {
                $table->unsignedInteger('vector_rank')->nullable()->after('retrieval_source');
            }

            if (! Schema::hasColumn('rag_query_chunks', 'keyword_rank')) {
                $table->unsignedInteger('keyword_rank')->nullable()->after('vector_rank');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('rag_query_chunks')) {
            return;
        }

        Schema::table('rag_query_chunks', function (Blueprint $table): void {
            $columns = [];

            foreach (['vector_score', 'keyword_score', 'retrieval_source', 'vector_rank', 'keyword_rank'] as $column) {
                if (Schema::hasColumn('rag_query_chunks', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
