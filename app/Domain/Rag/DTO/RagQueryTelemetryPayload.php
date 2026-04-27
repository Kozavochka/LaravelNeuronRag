<?php

declare(strict_types=1);

namespace App\Domain\Rag\DTO;

final readonly class RagQueryTelemetryPayload
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public RagQueryTelemetryMetrics $metrics,
        public RagQueryUsage $usage,
        public ?string $estimatedCostUsd = null,
        public array $metadata = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function metadataForPersistence(): array
    {
        return array_filter([
            'raw_usage' => $this->usage->rawUsage === [] ? null : $this->usage->rawUsage,
            'telemetry' => $this->metrics->toArray(),
            ...$this->metadata,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
