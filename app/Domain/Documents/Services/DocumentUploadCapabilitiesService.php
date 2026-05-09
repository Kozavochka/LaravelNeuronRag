<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services;

use App\Domain\Documents\Contracts\MarkitdownClientInterface;
use App\Domain\Documents\DTO\MarkitdownHealthResult;
use Illuminate\Support\Facades\Cache;

final class DocumentUploadCapabilitiesService
{
    public function __construct(
        private readonly MarkitdownClientInterface $markitdown,
    ) {
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $base = $this->baseExtensions();

        if ($this->health()->isAvailable) {
            return array_values(array_unique([...$base, ...$this->extendedExtensions()]));
        }

        return $base;
    }

    public function isExtensionAllowed(string $extension): bool
    {
        $extension = mb_strtolower(trim($extension));

        if ($extension === '') {
            return false;
        }

        $base = $this->baseExtensions();
        if (in_array($extension, $base, true)) {
            return true;
        }

        $extended = $this->extendedExtensions();
        if (! in_array($extension, $extended, true)) {
            return false;
        }

        return $this->health()->isAvailable;
    }

    /**
     * @return list<string>
     */
    public function baseExtensions(): array
    {
        /** @var array<int, string> $extensions */
        $extensions = config('rag.documents.allowed_extensions', ['md', 'docx']);

        return array_values(array_map(static fn (string $ext): string => mb_strtolower(trim($ext)), $extensions));
    }

    /**
     * @return list<string>
     */
    public function extendedExtensions(): array
    {
        /** @var array<int, string> $extensions */
        $extensions = config('rag.markitdown.extended_extensions', []);

        return array_values(array_map(static fn (string $ext): string => mb_strtolower(trim($ext)), $extensions));
    }

    public function health(): MarkitdownHealthResult
    {
        $ttl = max(1, (int) config('rag.markitdown.health_ttl_seconds', 30));
        /** @var mixed $cached */
        $cached = Cache::get('rag:markitdown:health');

        if (is_array($cached) && isset($cached['is_available'], $cached['status'])) {
            return new MarkitdownHealthResult(
                isAvailable: (bool) $cached['is_available'],
                status: (string) $cached['status'],
            );
        }

        $fresh = $this->markitdown->health();

        Cache::put('rag:markitdown:health', [
            'is_available' => $fresh->isAvailable,
            'status' => $fresh->status,
        ], $ttl);

        return $fresh;
    }
}
