<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

use App\Domain\Rag\DTO\RagChatRequest;
use App\Domain\Rag\DTO\RagChatResult;
use App\Domain\Rag\DTO\RagChatSource;
use App\Domain\Rag\DTO\RagQueryMetric;
use App\Domain\Rag\DTO\RagQueryUsage;
use App\Domain\Rag\Services\Telemetry\RagQueryTelemetry;
use App\Neuron\DocumentRAG;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Chat\Messages\Usage;

class RagChatRuntime
{
    public function __construct(
        private RagQueryLogger $queryLogger,
        private DocumentRAG $rag,
        private RagQueryTelemetry $telemetry,
        private CostEstimator $costEstimator,
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
        $this->telemetry->startTotal();

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

        $message = $this->telemetry->measure(
            RagQueryMetric::LlmMs,
            fn () => $rag->chat(UserMessage::make($request->question))->getMessage()
        );
        $answer = trim((string) ($message->getContent() ?? ''));
        $sources = array_map(
            static fn ($document, int $index): RagChatSource => RagChatSource::fromNeuronDocument($document, $index + 1),
            $rag->retrievedDocuments(),
            array_keys($rag->retrievedDocuments()),
        );

        $usage = $message->getUsage();
        $promptTokens = $usage instanceof Usage ? $usage->inputTokens : null;
        $completionTokens = $usage instanceof Usage ? $usage->outputTokens : null;
        $totalTokens = $usage instanceof Usage ? $usage->getTotal() : null;

        $this->telemetry->setUsage(new RagQueryUsage(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            rawUsage: $usage instanceof Usage ? $usage->jsonSerialize() : [],
        ));
        $this->telemetry->setEstimatedCost(
            $this->costEstimator->estimate(
                model: config('rag.llm.model', 'openrouter/auto'),
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
            )
        );
        $this->telemetry->mergeMetadata([
            'telemetry_unavailable' => [
                RagQueryMetric::PromptBuildMs->value => true,
            ],
        ]);
        $this->telemetry->finishTotal();

        $telemetryPayload = $this->telemetry->toPersistencePayload();
        $queryId = $this->queryLogger->log($request, $answer, $sources, $telemetryPayload);

        return new RagChatResult(
            answer: $answer,
            sources: $sources,
            queryId: $queryId,
            rerankMs: $telemetryPayload->metrics->rerankMs,
        );
    }
}
