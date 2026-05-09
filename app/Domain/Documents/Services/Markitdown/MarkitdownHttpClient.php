<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\Markitdown;

use App\Domain\Documents\Contracts\MarkitdownClientInterface;
use App\Domain\Documents\DTO\MarkitdownConversionResult;
use App\Domain\Documents\DTO\MarkitdownHealthResult;
use App\Domain\Documents\Services\Diagnostics\IntegrationEventLogger;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class MarkitdownHttpClient implements MarkitdownClientInterface
{
    public function __construct(
        private readonly IntegrationEventLogger $eventLogger,
    ) {
    }

    public function health(): MarkitdownHealthResult
    {
        if (! (bool) config('rag.markitdown.enabled', true)) {
            return new MarkitdownHealthResult(false, 'disabled');
        }

        $startedAt = microtime(true);

        try {
            $response = Http::timeout((int) config('rag.markitdown.timeout_seconds', 30))
                ->acceptJson()
                ->get($this->baseUrl() . '/health');

            $latencyMs = $this->latencyMs($startedAt);
            $status = (string) ($response->json('status') ?? 'down');
            $isAvailable = $response->successful() && $status === 'ok';

            $this->eventLogger->log(
                integration: 'markitdown',
                eventType: 'health',
                statusCode: $response->status(),
                latencyMs: $latencyMs,
                message: $isAvailable ? 'Markitdown health check passed.' : 'Markitdown health check failed.',
                context: ['status' => $status],
            );

            return new MarkitdownHealthResult($isAvailable, $isAvailable ? 'ok' : 'down');
        } catch (\Throwable $throwable) {
            $latencyMs = $this->latencyMs($startedAt);

            $this->eventLogger->log(
                integration: 'markitdown',
                eventType: 'health',
                statusCode: 0,
                latencyMs: $latencyMs,
                message: 'Markitdown health check exception: ' . $throwable->getMessage(),
            );

            return new MarkitdownHealthResult(false, 'down');
        }
    }

    public function convert(string $absolutePath, string $originalFilename, ?string $mimeType = null): MarkitdownConversionResult
    {
        $startedAt = microtime(true);
        $fileContent = file_get_contents($absolutePath);

        if ($fileContent === false) {
            throw new RuntimeException("Unable to read file for Markitdown conversion [{$absolutePath}].");
        }

        try {
            $response = Http::timeout((int) config('rag.markitdown.timeout_seconds', 30))
                ->acceptJson()
                ->attach('file', $fileContent, $originalFilename, ['Content-Type' => $mimeType ?? 'application/octet-stream'])
                ->post($this->baseUrl() . '/convert');

            $latencyMs = $this->latencyMs($startedAt);

            if (! $response->successful()) {
                $message = (string) ($response->json('detail') ?? $response->body());

                $this->eventLogger->log(
                    integration: 'markitdown',
                    eventType: 'convert',
                    statusCode: $response->status(),
                    latencyMs: $latencyMs,
                    message: 'Markitdown conversion failed: ' . $message,
                    context: ['filename' => $originalFilename],
                );

                throw new RuntimeException('Markitdown conversion failed: ' . $message);
            }

            $markdown = (string) ($response->json('markdown') ?? '');
            if ($markdown === '') {
                throw new RuntimeException('Markitdown conversion returned empty markdown.');
            }

            $this->eventLogger->log(
                integration: 'markitdown',
                eventType: 'convert',
                statusCode: $response->status(),
                latencyMs: $latencyMs,
                message: 'Markitdown conversion completed.',
                context: ['filename' => $originalFilename],
            );

            return new MarkitdownConversionResult(
                markdown: $markdown,
                filename: (string) ($response->json('filename') ?? $originalFilename),
                contentType: $response->json('content_type'),
            );
        } catch (\Throwable $throwable) {
            $latencyMs = $this->latencyMs($startedAt);

            $this->eventLogger->log(
                integration: 'markitdown',
                eventType: 'convert',
                statusCode: 0,
                latencyMs: $latencyMs,
                message: 'Markitdown conversion exception: ' . $throwable->getMessage(),
                context: ['filename' => $originalFilename],
            );

            throw $throwable;
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('rag.markitdown.base_url', 'http://localhost'), ':/')
            . ':' . (int) config('rag.markitdown.port', 8123);
    }

    private function latencyMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }
}
