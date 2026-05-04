<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_chunks') || DB::getDriverName() !== 'pgsql' || Schema::hasColumn('document_chunks', 'search_vector')) {
            return;
        }

        $dictionary = (string) config('rag.retrieval.ts_dictionary', 'simple');

        DB::statement('ALTER TABLE document_chunks ADD COLUMN search_vector tsvector');
        DB::statement('CREATE INDEX document_chunks_search_vector_idx ON document_chunks USING GIN (search_vector)');
        DB::statement(sprintf(
            "UPDATE document_chunks SET search_vector = to_tsvector('%s', coalesce(content, ''))",
            str_replace("'", "''", $dictionary),
        ));
    }

    public function down(): void
    {
        if (! Schema::hasTable('document_chunks') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS document_chunks_search_vector_idx');
        DB::statement('ALTER TABLE document_chunks DROP COLUMN IF EXISTS search_vector');
    }
};
