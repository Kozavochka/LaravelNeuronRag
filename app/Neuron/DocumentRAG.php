<?php

declare(strict_types=1);

namespace App\Neuron;

use App\Domain\Rag\PostProcessors\CaptureRetrievedDocumentsPostProcessor;
use App\Domain\Rag\PostProcessors\LimitContextPostProcessor;
use App\Domain\Rag\Support\RetrievedDocumentBuffer;
use App\Neuron\VectorStore\PgVectorStore;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\RAG;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

final class DocumentRAG extends RAG
{
    /**
     * @var array<string, mixed>
     */
    private array $filters = [];

    public function __construct(
        private readonly AIProviderInterface $runtimeAiProvider,
        private readonly EmbeddingsProviderInterface $runtimeEmbeddingsProvider,
        private readonly PgVectorStore $runtimeVectorStore,
        private readonly RetrievedDocumentBuffer $runtimeRetrievedDocumentBuffer,
        private readonly LimitContextPostProcessor $runtimeLimitContextPostProcessor,
    ) {
        parent::__construct();
    }

    public function forDocument(?int $documentId): self
    {
        $this->filters['document_id'] = $documentId;
        $this->setVectorStore($this->vectorStore());

        return $this;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function withFilters(array $filters): self
    {
        $this->filters = [
            ...$this->filters,
            ...array_filter($filters, static fn (mixed $value): bool => $value !== null),
        ];
        $this->setVectorStore($this->vectorStore());

        return $this;
    }

    public function withTopK(int $topK): self
    {
        $this->filters['top_k'] = max(1, $topK);
        $this->setVectorStore($this->vectorStore());

        return $this;
    }

    /**
     * @return array<int, \NeuronAI\RAG\Document>
     */
    public function retrievedDocuments(): array
    {
        return $this->runtimeRetrievedDocumentBuffer->all();
    }

    public function resetRuntimeState(): self
    {
        $this->filters = [];
        $this->runtimeRetrievedDocumentBuffer->clear();
        $this->setVectorStore($this->runtimeVectorStore->resetRuntimeState());

        return $this;
    }

    protected function provider(): AIProviderInterface
    {
        return $this->runtimeAiProvider;
    }

    protected function embeddings(): EmbeddingsProviderInterface
    {
        return $this->runtimeEmbeddingsProvider;
    }

    protected function vectorStore(): VectorStoreInterface
    {
        return $this->runtimeVectorStore
            ->resetRuntimeState()
            ->withFilters($this->filters)
            ->withTopK((int) ($this->filters['top_k'] ?? config('rag.retrieval.top_k', 8)));
    }

    protected function instructions(): string
    {
        return implode("\n", [
            'Ты RAG-помощник.',
            'Отвечай только на основе найденного контекста.',
            'Если в документах нет достаточной информации, прямо сообщи об этом.',
            'Не выдумывай факты и не добавляй внешние знания.',
            'Отвечай на русском языке.',
            'В конце кратко перечисли использованные источники.',
        ]);
    }

    protected function postProcessors(): array
    {
        return [
            $this->runtimeLimitContextPostProcessor,
            new CaptureRetrievedDocumentsPostProcessor($this->runtimeRetrievedDocumentBuffer),
        ];
    }
}
