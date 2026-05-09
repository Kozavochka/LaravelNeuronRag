<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rag;

use App\Domain\Documents\Jobs\ProcessDocumentJob;
use App\Domain\Documents\Services\DocumentImportService;
use App\Domain\Documents\Services\DocumentUploadCapabilitiesService;
use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('rag.http.pagination.max_per_page')],
            'status' => ['sometimes', 'string', 'max:50'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? config('rag.http.pagination.per_page'));
        $documents = Document::query()
            ->when(isset($validated['status']), fn ($query) => $query->where('status', $validated['status']))
            ->withCount([
                'versions',
                'chunks as active_chunks_count' => fn ($query) => $query->where('is_active', true),
            ])
            ->latest()
            ->paginate($perPage);

        return response()->json($documents);
    }

    public function store(Request $request, DocumentImportService $importService): JsonResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:' . config('rag.documents.max_upload_kb'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        $fail('The file field must contain an uploaded file.');

                        return;
                    }

                    $extension = mb_strtolower($value->getClientOriginalExtension());
                    $allowed = app(DocumentUploadCapabilitiesService::class)->allowedExtensions();

                    if (! in_array($extension, $allowed, true)) {
                        $fail('The file field must be a file of type: ' . implode(', ', $allowed) . '.');
                    }
                },
            ],
        ]);

        $document = $importService->import($validated['file']);
        ProcessDocumentJob::dispatch($document->id);

        return response()->json([
            'data' => [
                'id' => $document->id,
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'extension' => $document->extension,
                'status' => $document->status,
            ],
            'meta' => [
                'message' => 'Document queued for indexing.',
            ],
        ], 201);
    }

    public function show(Document $document): JsonResponse
    {
        $document->loadCount([
            'versions',
            'chunks as active_chunks_count' => fn ($query) => $query->where('is_active', true),
        ])->load([
            'versions' => fn ($query) => $query->latest()->limit(1),
        ]);

        return response()->json([
            'data' => [
                'id' => $document->id,
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'extension' => $document->extension,
                'status' => $document->status,
                'content_hash' => $document->content_hash,
                'error_message' => $document->error_message,
                'versions_count' => $document->versions_count,
                'active_chunks_count' => $document->active_chunks_count,
                'latest_version' => $document->versions->first(),
            ],
        ]);
    }

    public function reindex(Document $document): JsonResponse
    {
        ProcessDocumentJob::dispatch($document->id);

        return response()->json([
            'data' => [
                'id' => $document->id,
                'status' => 'queued',
            ],
        ]);
    }
}
