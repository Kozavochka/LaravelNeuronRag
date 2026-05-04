<?php

declare(strict_types=1);

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
            if (! Schema::hasColumn('rag_queries', 'keyword_search_ms')) {
                $table->unsignedInteger('keyword_search_ms')->nullable()->after('vector_search_ms');
            }

            if (! Schema::hasColumn('rag_queries', 'hybrid_merge_ms')) {
                $table->unsignedInteger('hybrid_merge_ms')->nullable()->after('keyword_search_ms');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('rag_queries')) {
            return;
        }

        Schema::table('rag_queries', function (Blueprint $table): void {
            $columns = [];

            if (Schema::hasColumn('rag_queries', 'keyword_search_ms')) {
                $columns[] = 'keyword_search_ms';
            }

            if (Schema::hasColumn('rag_queries', 'hybrid_merge_ms')) {
                $columns[] = 'hybrid_merge_ms';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
