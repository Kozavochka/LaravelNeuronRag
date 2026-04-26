<?php

declare(strict_types=1);

namespace App\Neuron\VectorStore;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use RuntimeException;

use function array_filter;
use function array_key_exists;
use function array_map;
use function array_values;
use function hash;
use function in_array;
use function is_array;
use function is_numeric;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function sprintf;

final class PgVectorStore implements VectorStoreInterface
{
    /**
     * @var array<string, mixed>
     */
    private array $filters = [];

    /**
     * @var Document[]
     */
    private array $lastResults = [];

    private ?array $columns = null;

    public function __construct(
        private readonly int $defaultTopK = 8,
    ) {
    }

    public function withFilters(array $filters): self
    {
        $this->filters = array_filter(
            [
                ...$this->filters,
                ...$filters,
            ],
            static fn (mixed $value): bool => $value !== null && $value !== []
        );

        return $this;
    }

    public function currentFilters(): array
    {
        return $this->filters;
    }

    public function withTopK(int $topK): self
    {
        $this->filters['top_k'] = max(1, $topK);

        return $this;
    }

    public function resetRuntimeState(): self
    {
        $this->filters = [];
        $this->lastResults = [];

        return $this;
    }

    /**
     * @return Document[]
     */
    public function lastResults(): array
    {
        return $this->lastResults;
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        if (! Schema::hasTable('document_chunks')) {
            throw new RuntimeException('The document_chunks table does not exist.');
        }

        if (! in_array('embedding', $this->columns(), true)) {
            throw new RuntimeException('The document_chunks.embedding vector column does not exist.');
        }

        foreach ($documents as $index => $document) {
            $this->insertDocument($document, $index);
        }

        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        if (! Schema::hasTable('document_chunks')) {
            return $this;
        }

        $query = DB::table('document_chunks');

        if (in_array('is_active', $this->columns(), true)) {
            $update = ['is_active' => false];

            if (in_array('updated_at', $this->columns(), true)) {
                $update['updated_at'] = now();
            }

            if ($sourceType === 'document' && $sourceName !== null && is_numeric($sourceName) && in_array('document_id', $this->columns(), true)) {
                $query->where('document_id', (int) $sourceName)->update($update);

                return $this;
            }

            if (in_array('metadata', $this->columns(), true)) {
                $query
                    ->whereRaw("metadata->>'source_type' = ?", [$sourceType])
                    ->when(
                        $sourceName !== null,
                        static fn ($builder) => $builder->whereRaw("metadata->>'source_name' = ?", [$sourceName])
                    )
                    ->update($update);
            }

            return $this;
        }

        if ($sourceType === 'document' && $sourceName !== null && is_numeric($sourceName) && in_array('document_id', $this->columns(), true)) {
            $query->where('document_id', (int) $sourceName)->delete();

            return $this;
        }

        if (in_array('metadata', $this->columns(), true)) {
            $query
                ->whereRaw("metadata->>'source_type' = ?", [$sourceType])
                ->when(
                    $sourceName !== null,
                    static fn ($builder) => $builder->whereRaw("metadata->>'source_name' = ?", [$sourceName])
                )
                ->delete();
        }

        return $this;
    }

    public function similaritySearch(array $embedding): iterable
    {
        if (! Schema::hasTable('document_chunks') || ! in_array('embedding', $this->columns(), true)) {
            $this->lastResults = [];

            return [];
        }

        $vector = $this->toPgVector($embedding);
        $conditions = [];
        $bindings = ['query_embedding' => $vector];

        if (in_array('is_active', $this->columns(), true)) {
            $conditions[] = 'is_active = true';
        }

        if (array_key_exists('document_id', $this->filters) && in_array('document_id', $this->columns(), true)) {
            $conditions[] = 'document_id = :document_id';
            $bindings['document_id'] = (int) $this->filters['document_id'];
        }

        if (array_key_exists('document_ids', $this->filters) && is_array($this->filters['document_ids']) && $this->filters['document_ids'] !== [] && in_array('document_id', $this->columns(), true)) {
            $placeholders = [];

            foreach (array_values($this->filters['document_ids']) as $index => $documentId) {
                $bindingKey = "document_id_{$index}";
                $placeholders[] = ":{$bindingKey}";
                $bindings[$bindingKey] = (int) $documentId;
            }

            $conditions[] = 'document_id IN (' . implode(', ', $placeholders) . ')';
        }

        if (in_array('metadata', $this->columns(), true) && isset($this->filters['metadata']) && is_array($this->filters['metadata'])) {
            foreach ($this->filters['metadata'] as $key => $value) {
                $bindingKey = 'meta_' . $key;
                $conditions[] = "metadata->>'{$key}' = :{$bindingKey}";
                $bindings[$bindingKey] = (string) $value;
            }
        }

        $selectColumns = ['id', 'content'];

        foreach (['document_id', 'document_version_id', 'chunk_index', 'heading', 'section_path', 'page_number', 'metadata'] as $column) {
            if (in_array($column, $this->columns(), true)) {
                $selectColumns[] = $column;
            }
        }

        $sql = '
            SELECT
                ' . implode(",\n                ", $selectColumns) . ',
                embedding <=> CAST(:query_embedding AS vector) AS distance
            FROM document_chunks
            ' . ($conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions)) . '
            ORDER BY embedding <=> CAST(:query_embedding AS vector)
            LIMIT ' . (int) ($this->filters['top_k'] ?? $this->defaultTopK);

        $rows = DB::select($sql, $bindings);

        $this->lastResults = array_map(function (object $row, int $index): Document {
            $metadata = $this->decodeMetadata($row->metadata ?? null);
            $distance = (float) $row->distance;

            $document = new Document($row->content);
            $document->id = (int) $row->id;
            $document->sourceType = (string) ($metadata['source_type'] ?? 'document_chunk');
            $document->sourceName = (string) ($metadata['source_name'] ?? $row->document_id ?? $row->id);
            $document->metadata = [
                ...$metadata,
                'chunk_id' => (int) $row->id,
                'document_id' => isset($row->document_id) ? (int) $row->document_id : ($metadata['document_id'] ?? null),
                'document_version_id' => isset($row->document_version_id) ? (int) $row->document_version_id : ($metadata['document_version_id'] ?? null),
                'chunk_index' => isset($row->chunk_index) ? (int) $row->chunk_index : ($metadata['chunk_index'] ?? $index),
                'heading' => $row->heading ?? ($metadata['heading'] ?? null),
                'section_path' => $row->section_path ?? ($metadata['section_path'] ?? null),
                'page_number' => isset($row->page_number) ? (int) $row->page_number : ($metadata['page_number'] ?? null),
                'distance' => $distance,
                'rank' => $index + 1,
            ];
            $document->setScore((float) VectorSimilarity::similarityFromDistance($distance));

            return $document;
        }, $rows, array_keys($rows));

        return $this->lastResults;
    }

    /**
     * @return string[]
     */
    private function columns(): array
    {
        return $this->columns ??= Schema::hasTable('document_chunks')
            ? Schema::getColumnListing('document_chunks')
            : [];
    }

    private function insertDocument(Document $document, int $index): void
    {
        if ($document->embedding === []) {
            throw new InvalidArgumentException('Cannot persist a document without embedding.');
        }

        $metadata = $this->normalizedMetadata($document, $index);
        $bindings = [];
        $columns = [];
        $placeholders = [];

        $payload = [
            'document_id' => $metadata['document_id'] ?? null,
            'document_version_id' => $metadata['document_version_id'] ?? null,
            'chunk_index' => $metadata['chunk_index'] ?? $index,
            'content' => $metadata['raw_content'] ?? $document->getContent(),
            'content_hash' => $metadata['content_hash'] ?? hash('sha256', (string) ($metadata['raw_content'] ?? $document->getContent())),
            'char_count' => $metadata['char_count'] ?? mb_strlen((string) ($metadata['raw_content'] ?? $document->getContent())),
            'token_estimate' => $metadata['token_estimate'] ?? null,
            'heading' => $metadata['heading'] ?? null,
            'section_path' => $metadata['section_path'] ?? null,
            'page_number' => $metadata['page_number'] ?? null,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'is_active' => $metadata['is_active'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
            'embedding' => $this->toPgVector($document->embedding),
        ];

        foreach ($payload as $column => $value) {
            if (! in_array($column, $this->columns(), true)) {
                continue;
            }

            $columns[] = $column;
            $bindings[$column] = $value;

            $placeholders[] = match ($column) {
                'embedding' => 'CAST(:embedding AS vector)',
                'metadata' => 'CAST(:metadata AS jsonb)',
                default => ':' . $column,
            };
        }

        if (in_array('document_id', $this->columns(), true) && ! isset($bindings['document_id'])) {
            throw new InvalidArgumentException('document_id metadata is required to persist document chunks.');
        }

        if ($columns === []) {
            throw new RuntimeException('No compatible columns were found in document_chunks for insert.');
        }

        $sql = sprintf(
            'INSERT INTO document_chunks (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', $placeholders),
        );

        DB::statement($sql, $bindings);
    }

    private function normalizedMetadata(Document $document, int $index): array
    {
        $metadata = $document->metadata;

        if (! array_key_exists('document_id', $metadata) && $document->sourceType === 'document' && is_numeric($document->sourceName)) {
            $metadata['document_id'] = (int) $document->sourceName;
        }

        $metadata['source_type'] = $metadata['source_type'] ?? $document->sourceType;
        $metadata['source_name'] = $metadata['source_name'] ?? $document->sourceName;
        $metadata['chunk_index'] = $metadata['chunk_index'] ?? $index;

        return $metadata;
    }

    private function decodeMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || $metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param float[] $embedding
     */
    private function toPgVector(array $embedding): string
    {
        return '[' . implode(',', array_map(
            static fn (float|int $value): string => sprintf('%.10F', (float) $value),
            $embedding
        )) . ']';
    }
}
