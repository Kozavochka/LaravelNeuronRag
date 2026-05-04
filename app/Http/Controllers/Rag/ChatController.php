<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rag;

use App\Domain\Rag\Services\RagChatRuntime;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function store(Request $request, RagChatRuntime $runtime): JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:5000'],
            'document_id' => ['nullable', 'integer', 'exists:documents,id'],
            'top_k' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'retrieval_mode' => ['nullable', 'in:vector,keyword,hybrid'],
        ]);

        $result = $runtime->answer(
            question: $validated['question'],
            documentId: $validated['document_id'] ?? null,
            topK: $validated['top_k'] ?? null,
            retrievalMode: $validated['retrieval_mode'] ?? null,
        );

        return response()->json([
            'data' => [
                ...$result->toArray(),
                'question' => $validated['question'],
                'document_id' => $validated['document_id'] ?? null,
                'retrieval_mode' => $validated['retrieval_mode'] ?? null,
            ],
        ]);
    }
}
