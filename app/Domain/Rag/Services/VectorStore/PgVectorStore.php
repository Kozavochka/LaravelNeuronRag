<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services\VectorStore;

use App\Domain\Rag\DTO\SourceChunk;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

final class PgVectorStore implements VectorStoreInterface
{
    public function __construct(
        private readonly ?int $documentIdFilter = null,
    ) {
    }

    public function withDocumentFilter(?int $documentId): self
    {
        return new self($documentId);
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        foreach ($documents as $document) {
            $metadata = $document->metadata;
            $payload = [
                'document_id' => (int) ($metadata['document_id'] ?? 0),
                'document_version_id' => (int) ($metadata['document_version_id'] ?? 0),
                'chunk_index' => (int) ($metadata['chunk_index'] ?? 0),
                'content' => $document->content,
                'content_hash' => (string) ($metadata['content_hash'] ?? hash('sha256', $document->content)),
                'char_count' => (int) ($metadata['char_count'] ?? mb_strlen($document->content)),
                'token_estimate' => (int) ($metadata['token_estimate'] ?? ceil(mb_strlen($document->content) / 4)),
                'heading' => $metadata['heading'] ?? null,
                'section_path' => $metadata['section_path'] ?? null,
                'page_number' => $metadata['page_number'] ?? null,
                'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'embedding' => $this->serializeEmbedding($document->embedding),
            ];

            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    <<<'SQL'
                    INSERT INTO document_chunks (
                        document_id,
                        document_version_id,
                        chunk_index,
                        content,
                        content_hash,
                        char_count,
                        token_estimate,
                        heading,
                        section_path,
                        page_number,
                        metadata,
                        is_active,
                        embedding,
                        created_at,
                        updated_at
                    )
                    VALUES (
                        :document_id,
                        :document_version_id,
                        :chunk_index,
                        :content,
                        :content_hash,
                        :char_count,
                        :token_estimate,
                        :heading,
                        :section_path,
                        :page_number,
                        :metadata,
                        true,
                        :embedding::vector,
                        NOW(),
                        NOW()
                    )
                    SQL,
                    $payload,
                );
            } else {
                DB::table('document_chunks')->insert([
                    'document_id' => $payload['document_id'],
                    'document_version_id' => $payload['document_version_id'],
                    'chunk_index' => $payload['chunk_index'],
                    'content' => $payload['content'],
                    'content_hash' => $payload['content_hash'],
                    'char_count' => $payload['char_count'],
                    'token_estimate' => $payload['token_estimate'],
                    'heading' => $payload['heading'],
                    'section_path' => $payload['section_path'],
                    'page_number' => $payload['page_number'],
                    'metadata' => $payload['metadata'],
                    'is_active' => true,
                    'embedding' => $payload['embedding'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return $this;
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $query = DocumentChunk::query()->where('is_active', true);

        if ($sourceType === 'document' && $sourceName !== null) {
            $query->where('document_id', (int) $sourceName);
        } else {
            $query->where('metadata->source_type', $sourceType);

            if ($sourceName !== null) {
                $query->where('metadata->source_name', $sourceName);
            }
        }

        $query->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    public function similaritySearch(array $embedding): iterable
    {
        $limit = (int) config('rag.retrieval.top_k', 8);

        if (DB::getDriverName() === 'pgsql') {
            $vector = $this->serializeEmbedding($embedding);
            $where = ['is_active = true'];
            $bindings = [
                'query_embedding_1' => $vector,
                'query_embedding_2' => $vector,
                'limit' => $limit,
            ];

            if ($this->documentIdFilter !== null) {
                $where[] = 'document_id = :document_id';
                $bindings['document_id'] = $this->documentIdFilter;
            }

            $rows = DB::select(
                'SELECT id, document_id, content, metadata, embedding <=> :query_embedding_1::vector AS distance
                 FROM document_chunks
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY embedding <=> :query_embedding_2::vector
                 LIMIT :limit',
                $bindings,
            );

            return array_map(function (object $row, int $index): Document {
                $metadata = json_decode((string) ($row->metadata ?? '{}'), true) ?: [];
                $score = (float) VectorSimilarity::similarityFromDistance((float) $row->distance);

                $document = new Document((string) $row->content);
                $document->id = (int) $row->id;
                $document->sourceType = 'document';
                $document->sourceName = (string) $row->document_id;
                $document->metadata = $metadata + [
                    'chunk_id' => (int) $row->id,
                    'distance' => (float) $row->distance,
                    'rank' => $index + 1,
                ];

                return $document->setScore($score);
            }, $rows, array_keys($rows));
        }

        $query = DocumentChunk::query()
            ->where('is_active', true)
            ->when($this->documentIdFilter !== null, fn ($builder) => $builder->where('document_id', $this->documentIdFilter))
            ->limit($limit);

        return $query->get()->map(function (DocumentChunk $chunk, int $index): Document {
            $document = new Document($chunk->content);
            $document->id = $chunk->id;
            $document->sourceType = 'document';
            $document->sourceName = (string) $chunk->document_id;
            $document->metadata = ($chunk->metadata ?? []) + [
                'chunk_id' => $chunk->id,
                'distance' => 0.0,
                'rank' => $index + 1,
            ];

            return $document->setScore(1.0);
        })->all();
    }

    /**
     * @return array<int, SourceChunk>
     */
    public function sourceChunksForEmbedding(array $embedding): array
    {
        return array_map(
            fn (Document $document): SourceChunk => new SourceChunk(
                chunkId: (int) ($document->metadata['chunk_id'] ?? $document->id),
                documentId: (int) $document->sourceName,
                content: $document->content,
                metadata: $document->metadata,
                distance: (float) ($document->metadata['distance'] ?? 0.0),
                score: (float) $document->score,
                rank: (int) ($document->metadata['rank'] ?? 0),
            ),
            [...$this->similaritySearch($embedding)],
        );
    }

    /**
     * @param array<int, float|int> $embedding
     */
    private function serializeEmbedding(array $embedding): string
    {
        return '[' . implode(',', array_map(
            static fn (float|int $value): string => sprintf('%.10F', (float) $value),
            $embedding,
        )) . ']';
    }
}
