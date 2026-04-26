<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

use App\Domain\Rag\DTO\RagAnswer;
use App\Domain\Rag\Services\VectorStore\PgVectorStore;
use App\Models\RagQuery;
use App\Models\RagQueryChunk;
use App\Neuron\DocumentRAG;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

final class RagChatService
{
    public function __construct(
        private readonly EmbeddingsProviderInterface $embeddings,
        private readonly PgVectorStore $vectorStore,
    ) {
    }

    public function answer(string $question, ?int $documentId = null): RagAnswer
    {
        $queryEmbedding = $this->embeddings->embedText($question);
        $store = $this->vectorStore->withDocumentFilter($documentId);
        $sources = $store->sourceChunksForEmbedding($queryEmbedding);

        $rag = app(DocumentRAG::class)->forDocument($documentId);
        $message = $rag->chat(new UserMessage($question))->getMessage();
        $answer = $message->getContent();

        $query = RagQuery::query()->create([
            'question' => $question,
            'answer' => $answer,
            'llm_provider' => config('rag.llm.provider', 'openrouter'),
            'llm_model' => config('rag.llm.model'),
            'embedding_model' => config('rag.embedding.model'),
            'top_k' => count($sources),
            'metadata' => [
                'document_id' => $documentId,
            ],
        ]);

        foreach ($sources as $source) {
            RagQueryChunk::query()->create([
                'rag_query_id' => $query->id,
                'document_chunk_id' => $source->chunkId,
                'distance' => $source->distance,
                'score' => $source->score,
                'rank' => $source->rank,
            ]);
        }

        return new RagAnswer($answer, $sources);
    }
}
