<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Documents\Jobs\ProcessDocumentJob;
use App\Domain\Documents\Services\DocumentImportService;
use App\Domain\Documents\Services\DocumentUploadCapabilitiesService;
use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\View\View;

final class AdminDocumentController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'max:50'],
            'has_errors' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:255'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'sort' => ['sometimes', 'in:created_at,updated_at,status,title'],
            'dir' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $sort = $validated['sort'] ?? 'created_at';
        $direction = $validated['dir'] ?? 'desc';
        $perPage = (int) ($validated['per_page'] ?? 20);

        $documents = Document::query()
            ->withCount([
                'versions',
                'chunks',
                'activeChunks',
            ])
            ->when(isset($validated['status']), fn (Builder $query) => $query->where('status', $validated['status']))
            ->when(($validated['has_errors'] ?? false) === true, fn (Builder $query) => $query->whereNotNull('error_message'))
            ->when(isset($validated['q']), function (Builder $query) use ($validated): void {
                $q = trim($validated['q']);

                $query->where(function (Builder $inner) use ($q): void {
                    $inner->where('title', 'like', "%{$q}%")
                        ->orWhere('original_filename', 'like', "%{$q}%");
                });
            })
            ->when(isset($validated['date_from']), fn (Builder $query) => $query->whereDate('created_at', '>=', $validated['date_from']))
            ->when(isset($validated['date_to']), fn (Builder $query) => $query->whereDate('created_at', '<=', $validated['date_to']))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.documents.index', [
            'documents' => $documents,
            'filters' => $validated,
        ]);
    }

    public function create(): View
    {
        $capabilities = app(DocumentUploadCapabilitiesService::class);

        return view('admin.documents.create', [
            'markitdownHealth' => $capabilities->health(),
            'allowedExtensions' => $capabilities->allowedExtensions(),
        ]);
    }

    public function store(Request $request, DocumentImportService $importService): RedirectResponse
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

        return redirect()
            ->route('admin.documents.show', $document)
            ->with('status', 'Document uploaded and queued for indexing.');
    }

    public function show(Document $document): View
    {
        $document->loadCount([
            'versions',
            'chunks',
            'activeChunks',
        ])->load([
            'versions' => fn ($query) => $query->latest()->limit(5),
        ]);

        return view('admin.documents.show', [
            'document' => $document,
        ]);
    }

    public function versions(Document $document, Request $request): View
    {
        $perPage = (int) $request->integer('per_page', 20);

        $versions = $document->versions()
            ->withCount('chunks')
            ->latest('id')
            ->paginate(max(1, min($perPage, 100)))
            ->withQueryString();

        return view('admin.documents.versions', [
            'document' => $document,
            'versions' => $versions,
        ]);
    }

    public function chunks(Document $document, Request $request): View
    {
        $validated = $request->validate([
            'active' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:255'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);

        $chunks = $document->chunks()
            ->when(! array_key_exists('active', $validated) || (bool) $validated['active'] === true, fn (Builder $query) => $query->where('is_active', true))
            ->when(isset($validated['q']), function (Builder $query) use ($validated): void {
                $q = trim($validated['q']);

                $query->where(function (Builder $inner) use ($q): void {
                    $inner->where('content', 'like', "%{$q}%")
                        ->orWhere('heading', 'like', "%{$q}%")
                        ->orWhere('section_path', 'like', "%{$q}%");
                });
            })
            ->orderBy('chunk_index')
            ->paginate($perPage)
            ->withQueryString();

        return view('admin.documents.chunks', [
            'document' => $document,
            'chunks' => $chunks,
            'filters' => $validated,
        ]);
    }

    public function reindex(Document $document): RedirectResponse
    {
        ProcessDocumentJob::dispatch($document->id);

        return redirect()
            ->route('admin.documents.show', $document)
            ->with('status', 'Reindex job has been queued.');
    }

    public function destroy(Document $document): RedirectResponse
    {
        $document->delete();

        return redirect()
            ->route('admin.documents.index')
            ->with('status', 'Document deleted.');
    }
}
