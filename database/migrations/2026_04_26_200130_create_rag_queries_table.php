<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rag_queries')) {
            return;
        }

        Schema::create('rag_queries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->longText('answer')->nullable();
            $table->string('llm_provider')->nullable();
            $table->string('llm_model')->nullable();
            $table->string('embedding_model')->nullable();
            $table->integer('top_k')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rag_queries');
    }
};
