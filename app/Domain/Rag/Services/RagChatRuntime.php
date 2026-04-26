<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

use App\Domain\Rag\DTO\RagChatRequest;
use App\Domain\Rag\DTO\RagChatResult;
use App\Domain\Rag\DTO\RagChatSource;
use App\Neuron\DocumentRAG;
use NeuronAI\Chat\Messages\UserMessage;

class RagChatRuntime
{
    public function __construct(
        private RagQueryLogger $queryLogger,
        private DocumentRAG $rag,
    ) {
    }

    public function answer(
        string $question,
        ?int $documentId = null,
        ?int $userId = null,
        array $filters = [],
        ?int $topK = null,
    ): RagChatResult {
        return $this->answerRequest(new RagChatRequest(
            question: $question,
            documentId: $documentId,
            userId: $userId,
            topK: $topK,
            filters: $filters,
        ));
    }

    public function answerRequest(RagChatRequest $request): RagChatResult
    {
        $rag = $this->rag;
        $rag->resetRuntimeState();

        if ($request->documentId !== null) {
            $rag->forDocument($request->documentId);
        }

        if ($request->filters !== []) {
            $rag->withFilters($request->filters);
        }

        if ($request->topK !== null) {
            $rag->withTopK($request->topK);
        }

        $message = $rag->chat(UserMessage::make($request->question))->getMessage();
        $answer = trim((string) ($message->getContent() ?? ''));
        $sources = array_map(
            static fn ($document, int $index): RagChatSource => RagChatSource::fromNeuronDocument($document, $index + 1),
            $rag->retrievedDocuments(),
            array_keys($rag->retrievedDocuments()),
        );

        $queryId = $this->queryLogger->log($request, $answer, $sources);

        return new RagChatResult(
            answer: $answer,
            sources: $sources,
            queryId: $queryId,
        );
    }
}
