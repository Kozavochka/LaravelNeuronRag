<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rag_query_chunks') || Schema::hasColumn('rag_query_chunks', 'rerank_score')) {
            return;
        }

        Schema::table('rag_query_chunks', function (Blueprint $table): void {
            $table->float('rerank_score')->nullable()->after('score');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('rag_query_chunks') || ! Schema::hasColumn('rag_query_chunks', 'rerank_score')) {
            return;
        }

        Schema::table('rag_query_chunks', function (Blueprint $table): void {
            $table->dropColumn('rerank_score');
        });
    }
};
