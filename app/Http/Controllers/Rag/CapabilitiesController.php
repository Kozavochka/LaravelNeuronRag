<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rag;

use App\Domain\Documents\Services\DocumentUploadCapabilitiesService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class CapabilitiesController extends Controller
{
    public function __invoke(DocumentUploadCapabilitiesService $capabilities): JsonResponse
    {
        $health = $capabilities->health();

        return response()->json([
            'data' => [
                'markitdown' => [
                    'enabled' => (bool) config('rag.markitdown.enabled', true),
                    'health' => [
                        'status' => $health->status,
                        'is_available' => $health->isAvailable,
                    ],
                    'endpoint' => sprintf('%s:%d', (string) config('rag.markitdown.base_url', 'http://localhost'), (int) config('rag.markitdown.port', 8123)),
                ],
                'documents' => [
                    'base_extensions' => $capabilities->baseExtensions(),
                    'extended_extensions' => $capabilities->extendedExtensions(),
                    'allowed_extensions' => $capabilities->allowedExtensions(),
                ],
            ],
        ]);
    }
}
