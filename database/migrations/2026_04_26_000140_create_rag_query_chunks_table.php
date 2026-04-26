<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rag_query_chunks')) {
            return;
        }

        Schema::create('rag_query_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rag_query_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_chunk_id')->constrained()->cascadeOnDelete();
            $table->float('distance')->nullable();
            $table->float('score')->nullable();
            $table->integer('rank')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_query_chunks');
    }
};
