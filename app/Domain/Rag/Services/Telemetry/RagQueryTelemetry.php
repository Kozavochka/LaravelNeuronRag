<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services\Telemetry;

use App\Domain\Rag\DTO\RagQueryMetric;
use App\Domain\Rag\DTO\RagQueryTelemetryMetrics;
use App\Domain\Rag\DTO\RagQueryTelemetryPayload;
use App\Domain\Rag\DTO\RagQueryUsage;

final class RagQueryTelemetry
{
    private ?float $startedAt = null;

    /**
     * @var array<string, int|null>
     */
    private array $metrics = [];

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

    public function __construct()
    {
        $this->metrics = RagQueryMetric::defaults();
    }

    public function startTotal(): void
    {
        $this->startedAt = microtime(true);
    }

    public function finishTotal(): ?int
    {
        if ($this->startedAt === null) {
            return $this->metrics[RagQueryMetric::TotalMs->value];
        }

        $total = (int) round((microtime(true) - $this->startedAt) * 1000);
        $this->metrics[RagQueryMetric::TotalMs->value] = $total;

        return $total;
    }

    public function measure(RagQueryMetric $metric, callable $callback): mixed
    {
        $startedAt = microtime(true);

        try {
            return $callback();
        } finally {
            $this->putMetric($metric, (int) round((microtime(true) - $startedAt) * 1000));
        }
    }

    public function putMetric(RagQueryMetric $metric, ?int $value): void
    {
        $this->metrics[$metric->value] = $value;
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
                embeddingMs: $this->metrics[RagQueryMetric::EmbeddingMs->value],
                vectorSearchMs: $this->metrics[RagQueryMetric::VectorSearchMs->value],
                keywordSearchMs: $this->metrics[RagQueryMetric::KeywordSearchMs->value],
                hybridMergeMs: $this->metrics[RagQueryMetric::HybridMergeMs->value],
                rerankMs: $this->metrics[RagQueryMetric::RerankMs->value],
                promptBuildMs: $this->metrics[RagQueryMetric::PromptBuildMs->value],
                llmMs: $this->metrics[RagQueryMetric::LlmMs->value],
                totalMs: $this->metrics[RagQueryMetric::TotalMs->value],
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
