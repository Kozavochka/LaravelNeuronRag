<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('integration_events')) {
            return;
        }

        Schema::create('integration_events', function (Blueprint $table): void {
            $table->id();
            $table->string('integration', 64);
            $table->string('event_type', 64);
            $table->integer('status_code')->default(0);
            $table->integer('latency_ms')->default(0);
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['integration', 'created_at']);
            $table->index(['integration', 'event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
