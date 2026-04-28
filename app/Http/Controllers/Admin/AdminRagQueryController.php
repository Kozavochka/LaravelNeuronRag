<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\RagQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AdminRagQueryController extends Controller
{
    public function index(Request $request): View
    {
        $validated = $request->validate([
            'document_id' => ['sometimes', 'integer', 'exists:documents,id'],
            'llm_model' => ['sometimes', 'string', 'max:255'],
            'has_errors' => ['sometimes', 'boolean'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date'],
            'q' => ['sometimes', 'string', 'max:255'],
            'sort' => ['sometimes', 'in:created_at,total_ms,total_tokens,estimated_cost_usd'],
            'dir' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $sort = $validated['sort'] ?? 'created_at';
        $direction = $validated['dir'] ?? 'desc';
        $perPage = (int) ($validated['per_page'] ?? 25);

        $queries = RagQuery::query()
            ->when(isset($validated['document_id']), function (Builder $query) use ($validated): void {
                $documentId = (int) $validated['document_id'];
                $query->where('metadata', 'like', '%"document_id":' . $documentId . '%');
            })
            ->when(isset($validated['llm_model']), fn (Builder $query) => $query->where('llm_model', $validated['llm_model']))
            ->when(isset($validated['date_from']), fn (Builder $query) => $query->whereDate('created_at', '>=', $validated['date_from']))
            ->when(isset($validated['date_to']), fn (Builder $query) => $query->whereDate('created_at', '<=', $validated['date_to']))
            ->when(($validated['has_errors'] ?? false) === true, function (Builder $query): void {
                $query->where(function (Builder $inner): void {
                    $inner->whereNull('answer')
                        ->orWhere('answer', '');
                });
            })
            ->when(isset($validated['q']), function (Builder $query) use ($validated): void {
                $q = trim($validated['q']);
                $query->where(function (Builder $inner) use ($q): void {
                    $inner->where('question', 'like', "%{$q}%")
                        ->orWhere('answer', 'like', "%{$q}%");
                });
            })
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString();

        $documents = Document::query()->orderBy('title')->limit(200)->get(['id', 'title']);
        $models = RagQuery::query()->select('llm_model')->whereNotNull('llm_model')->distinct()->orderBy('llm_model')->pluck('llm_model');

        return view('admin.rag-queries.index', [
            'queries' => $queries,
            'documents' => $documents,
            'models' => $models,
            'filters' => $validated,
        ]);
    }

    public function show(RagQuery $ragQuery): View
    {
        $sourceDocumentIds = collect($ragQuery->metadata['sources'] ?? [])
            ->pluck('document_id')
            ->filter(static fn ($value): bool => is_numeric($value))
            ->map(static fn ($value): int => (int) $value)
            ->unique()
            ->values();

        $documents = Document::query()
            ->whereIn('id', $sourceDocumentIds)
            ->get()
            ->keyBy('id');

        $queryChunks = DB::table('rag_query_chunks')
            ->where('rag_query_id', $ragQuery->id)
            ->orderBy('rank')
            ->get();

        $apiResponsePreview = [
            'data' => [
                'question' => $ragQuery->question,
                'document_id' => $ragQuery->metadata['document_id'] ?? null,
                'answer' => $ragQuery->answer,
                'query_id' => $ragQuery->id,
                'rerank_ms' => $ragQuery->rerank_ms,
                'sources' => $ragQuery->metadata['sources'] ?? [],
            ],
        ];

        return view('admin.rag-queries.show', [
            'query' => $ragQuery,
            'documents' => $documents,
            'sources' => $ragQuery->metadata['sources'] ?? [],
            'queryChunks' => $queryChunks,
            'apiResponsePreview' => $apiResponsePreview,
        ]);
    }
}
