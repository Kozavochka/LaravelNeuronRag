<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_versions')) {
            Schema::create('document_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('document_id')->constrained()->cascadeOnDelete();
                $table->string('version_hash', 64);
                $table->longText('raw_text')->nullable();
                $table->longText('normalized_text')->nullable();
                $table->json('metadata')->nullable();
                $table->string('status')->default('pending');
                $table->timestamps();

                $table->unique(['document_id', 'version_hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
