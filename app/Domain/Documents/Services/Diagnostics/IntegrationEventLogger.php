<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\Diagnostics;

use App\Models\IntegrationEvent;

final class IntegrationEventLogger
{
    /**
     * @param array<string, mixed> $context
     */
    public function log(string $integration, string $eventType, int $statusCode, int $latencyMs, string $message, array $context = []): void
    {
        IntegrationEvent::query()->create([
            'integration' => $integration,
            'event_type' => $eventType,
            'status_code' => $statusCode,
            'latency_ms' => $latencyMs,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
