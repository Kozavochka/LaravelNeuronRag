<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services\Telemetry;

use App\Domain\Rag\DTO\RagQueryTelemetryMetrics;
use App\Domain\Rag\DTO\RagQueryTelemetryPayload;
use App\Domain\Rag\DTO\RagQueryUsage;

final class RagQueryTelemetry
{
    private ?float $startedAt = null;

    /**
     * @var array<string, int|null>
     */
    private array $metrics = [
        'embedding_ms' => null,
        'vector_search_ms' => null,
        'rerank_ms' => null,
        'prompt_build_ms' => null,
        'llm_ms' => null,
        'total_ms' => null,
    ];

    private ?int $promptTokens = null;

    private ?int $completionTokens = null;

    private ?int $totalTokens = null;

    private ?string $estimatedCostUsd = null;

    /**
     * @var array<string, mixed>
     */
    private array $rawUsage = [];

    /**
     * @var array<string, mixed>
     */
    private array $metadata = [];

    public function startTotal(): void
    {
        $this->startedAt = microtime(true);
    }

    public function finishTotal(): ?int
    {
        if ($this->startedAt === null) {
            return $this->metrics['total_ms'];
        }

        $total = (int) round((microtime(true) - $this->startedAt) * 1000);
        $this->metrics['total_ms'] = $total;

        return $total;
    }

    public function measure(string $metric, callable $callback): mixed
    {
        $startedAt = microtime(true);

        try {
            return $callback();
        } finally {
            $this->putMetric($metric, (int) round((microtime(true) - $startedAt) * 1000));
        }
    }

    public function putMetric(string $metric, ?int $value): void
    {
        $this->metrics[$metric] = $value;
    }

    public function setUsage(RagQueryUsage $usage): void
    {
        $this->promptTokens = $usage->promptTokens;
        $this->completionTokens = $usage->completionTokens;
        $this->totalTokens = $usage->totalTokens;
        $this->rawUsage = $usage->rawUsage;
    }

    public function setEstimatedCost(?string $value): void
    {
        $this->estimatedCostUsd = $value;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function mergeMetadata(array $metadata): void
    {
        $this->metadata = [
            ...$this->metadata,
            ...$metadata,
        ];
    }

    public function toPersistencePayload(): RagQueryTelemetryPayload
    {
        return new RagQueryTelemetryPayload(
            metrics: new RagQueryTelemetryMetrics(
                embeddingMs: $this->metrics['embedding_ms'],
                vectorSearchMs: $this->metrics['vector_search_ms'],
                rerankMs: $this->metrics['rerank_ms'],
                promptBuildMs: $this->metrics['prompt_build_ms'],
                llmMs: $this->metrics['llm_ms'],
                totalMs: $this->metrics['total_ms'],
            ),
            usage: new RagQueryUsage(
                promptTokens: $this->promptTokens,
                completionTokens: $this->completionTokens,
                totalTokens: $this->totalTokens,
                rawUsage: $this->rawUsage,
            ),
            estimatedCostUsd: $this->estimatedCostUsd,
            metadata: $this->metadata,
        );
    }
}
