<?php

declare(strict_types=1);

namespace App\Neuron\VectorStore;

use App\Domain\Rag\DTO\RagQueryMetric;
use App\Domain\Rag\Services\Telemetry\RagQueryTelemetry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use RuntimeException;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_sum;
use function array_values;
use function count;
use function floor;
use function hash;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function max;
use function min;
use function sprintf;
use function strtolower;
use function trim;
use function usort;

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
        private readonly string $defaultRetrievalMode = 'hybrid',
        private readonly int $defaultVectorCandidates = 30,
        private readonly int $defaultKeywordCandidates = 30,
        private readonly float $vectorWeight = 0.7,
        private readonly float $keywordWeight = 0.3,
        private readonly string $tsDictionary = 'simple',
        private readonly ?RagQueryTelemetry $telemetry = null,
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

        $mode = $this->resolvedRetrievalMode();
        $topK = max(1, (int) ($this->filters['top_k'] ?? $this->defaultTopK));
        $query = trim((string) ($this->filters['query'] ?? ''));
        $this->telemetry?->mergeMetadata([
            'retrieval' => [
                'mode' => (string) ($this->filters['retrieval_mode'] ?? $this->defaultRetrievalMode),
                'resolved_mode' => $mode,
                'vector_candidates' => $this->defaultVectorCandidates,
                'keyword_candidates' => $this->defaultKeywordCandidates,
                'final_top_k' => $topK,
                'weights' => [
                    'vector' => $this->vectorWeight,
                    'keyword' => $this->keywordWeight,
                ],
                'ts_dictionary' => $this->tsDictionary,
            ],
        ]);

        $results = match ($mode) {
            'keyword' => $this->keywordSearch($query, $topK),
            'hybrid' => $this->hybridSearch($embedding, $query, $topK),
            default => $this->vectorSearch($embedding, $topK),
        };

        $this->lastResults = $results;

        return $results;
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
            $placeholder = match ($column) {
                'embedding' => 'CAST(:embedding AS vector)',
                'metadata' => 'CAST(:metadata AS jsonb)',
                default => ':' . $column,
            };

            $bindings[$column] = $value;
            $placeholders[] = $placeholder;
        }

        if (in_array('search_vector', $this->columns(), true) && in_array('content', $this->columns(), true)) {
            $columns[] = 'search_vector';
            $bindings['search_vector_dictionary'] = $this->tsDictionary;
            $placeholders[] = "to_tsvector(:search_vector_dictionary, coalesce(:content, ''))";
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

    private function resolvedRetrievalMode(): string
    {
        $mode = strtolower(trim((string) ($this->filters['retrieval_mode'] ?? $this->defaultRetrievalMode)));
        $supportsKeywordSearch = $this->supportsKeywordSearch();

        if ($supportsKeywordSearch) {
            return match ($mode) {
                'vector', 'keyword', 'hybrid' => $mode,
                default => $this->defaultRetrievalMode,
            };
        }

        $resolvedMode = 'vector';
        $requestedMode = match ($mode) {
            'vector', 'keyword', 'hybrid' => $mode,
            default => $this->defaultRetrievalMode,
        };

        if ($requestedMode !== 'vector') {
            $this->telemetry?->mergeMetadata([
                'retrieval' => [
                    'degraded' => true,
                    'degradation_reason' => 'keyword_search_unavailable',
                    'requested_mode' => $requestedMode,
                    'resolved_mode' => $resolvedMode,
                ],
            ]);
        }

        return $resolvedMode;
    }

    private function supportsKeywordSearch(): bool
    {
        return DB::getDriverName() === 'pgsql' && in_array('search_vector', $this->columns(), true);
    }

    /**
     * @param list<float|int> $embedding
     * @return array<int, Document>
     */
    private function vectorSearch(array $embedding, int $limit): array
    {
        $vector = $this->toPgVector($embedding);
        $bindings = ['query_embedding' => $vector];
        $conditions = $this->baseConditions($bindings);
        $selectColumns = $this->selectColumns();

        $sql = '
            SELECT
                ' . implode(",\n                ", $selectColumns) . ',
                embedding <=> CAST(:query_embedding AS vector) AS distance
            FROM document_chunks
            ' . ($conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions)) . '
            ORDER BY embedding <=> CAST(:query_embedding AS vector)
            LIMIT ' . $limit;

        $rows = $this->telemetry?->measure(
            RagQueryMetric::VectorSearchMs,
            fn (): array => DB::select($sql, $bindings)
        ) ?? DB::select($sql, $bindings);

        return array_map(
            fn (object $row, int $index): Document => $this->mapVectorRowToDocument($row, $index),
            $rows,
            array_keys($rows),
        );
    }

    /**
     * @return array<int, Document>
     */
    private function keywordSearch(string $query, int $limit): array
    {
        if ($query === '') {
            return [];
        }

        $bindings = [
            'ts_dictionary' => $this->tsDictionary,
            'query_text' => $query,
        ];
        $conditions = $this->baseConditions($bindings);
        $conditions[] = 'search_vector @@ plainto_tsquery(:ts_dictionary, :query_text)';
        $selectColumns = $this->selectColumns();

        $sql = '
            SELECT
                ' . implode(",\n                ", $selectColumns) . ',
                ts_rank_cd(search_vector, plainto_tsquery(:ts_dictionary, :query_text)) AS keyword_rank_score
            FROM document_chunks
            WHERE ' . implode(' AND ', $conditions) . '
            ORDER BY keyword_rank_score DESC, id ASC
            LIMIT ' . $limit;

        $rows = $this->telemetry?->measure(
            RagQueryMetric::KeywordSearchMs,
            fn (): array => DB::select($sql, $bindings)
        ) ?? DB::select($sql, $bindings);

        if ($rows === []) {
            return [];
        }

        $maxScore = max(0.000000001, ...array_map(
            static fn (object $row): float => max(0.0, (float) ($row->keyword_rank_score ?? 0.0)),
            $rows
        ));

        return array_map(
            fn (object $row, int $index): Document => $this->mapKeywordRowToDocument($row, $index, $maxScore),
            $rows,
            array_keys($rows),
        );
    }

    /**
     * @param list<float|int> $embedding
     * @return array<int, Document>
     */
    private function hybridSearch(array $embedding, string $query, int $limit): array
    {
        $vectorResults = $this->vectorSearch($embedding, max($limit, $this->defaultVectorCandidates));
        $keywordResults = $this->keywordSearch($query, max($limit, $this->defaultKeywordCandidates));

        return $this->telemetry?->measure(
            RagQueryMetric::HybridMergeMs,
            fn (): array => $this->mergeHybridResults($vectorResults, $keywordResults, $limit)
        ) ?? $this->mergeHybridResults($vectorResults, $keywordResults, $limit);
    }

    /**
     * @param array<string, mixed> $bindings
     * @return list<string>
     */
    private function baseConditions(array &$bindings): array
    {
        $conditions = [];

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

        return $conditions;
    }

    /**
     * @return list<string>
     */
    private function selectColumns(): array
    {
        $selectColumns = ['id', 'content'];

        foreach (['document_id', 'document_version_id', 'chunk_index', 'heading', 'section_path', 'page_number', 'metadata'] as $column) {
            if (in_array($column, $this->columns(), true)) {
                $selectColumns[] = $column;
            }
        }

        return $selectColumns;
    }

    private function mapVectorRowToDocument(object $row, int $index): Document
    {
        $distance = (float) $row->distance;
        $vectorScore = (float) VectorSimilarity::similarityFromDistance($distance);

        return $this->hydrateDocument(
            row: $row,
            rank: $index + 1,
            score: $vectorScore,
            metadata: [
                'distance' => $distance,
                'vector_score' => $vectorScore,
                'keyword_score' => 0.0,
                'final_score' => $vectorScore,
                'retrieval_source' => 'vector',
                'vector_rank' => $index + 1,
                'keyword_rank' => null,
            ],
        );
    }

    private function mapKeywordRowToDocument(object $row, int $index, float $maxScore): Document
    {
        $keywordScore = max(0.0, (float) ($row->keyword_rank_score ?? 0.0)) / $maxScore;

        return $this->hydrateDocument(
            row: $row,
            rank: $index + 1,
            score: $keywordScore,
            metadata: [
                'distance' => null,
                'vector_score' => 0.0,
                'keyword_score' => $keywordScore,
                'final_score' => $keywordScore,
                'retrieval_source' => 'keyword',
                'vector_rank' => null,
                'keyword_rank' => $index + 1,
            ],
        );
    }

    /**
     * @param array<int, Document> $vectorResults
     * @param array<int, Document> $keywordResults
     * @return array<int, Document>
     */
    private function mergeHybridResults(array $vectorResults, array $keywordResults, int $limit): array
    {
        /** @var array<int, Document> $merged */
        $merged = [];

        foreach ($vectorResults as $document) {
            $merged[(int) ($document->metadata['chunk_id'] ?? $document->id)] = $document;
        }

        foreach ($keywordResults as $document) {
            $chunkId = (int) ($document->metadata['chunk_id'] ?? $document->id);

            if (! isset($merged[$chunkId])) {
                $merged[$chunkId] = $document;

                continue;
            }

            $existing = $merged[$chunkId];
            $existing->metadata['keyword_score'] = (float) ($document->metadata['keyword_score'] ?? 0.0);
            $existing->metadata['keyword_rank'] = $document->metadata['keyword_rank'] ?? null;
            $existing->metadata['retrieval_source'] = 'hybrid';
            $merged[$chunkId] = $existing;
        }

        foreach ($merged as $chunkId => $document) {
            $vectorScore = (float) ($document->metadata['vector_score'] ?? 0.0);
            $keywordScore = (float) ($document->metadata['keyword_score'] ?? 0.0);
            $finalScore = ($this->vectorWeight * $vectorScore) + ($this->keywordWeight * $keywordScore);
            $document->metadata['final_score'] = $finalScore;
            $document->metadata['retrieval_source'] = $document->metadata['retrieval_source'] ?? ($keywordScore > 0.0 ? 'keyword' : 'vector');
            $document->setScore($finalScore);
            $merged[$chunkId] = $document;
        }

        $mergedResults = array_values($merged);

        usort($mergedResults, static function (Document $left, Document $right): int {
            $leftScore = (float) ($left->metadata['final_score'] ?? $left->getScore());
            $rightScore = (float) ($right->metadata['final_score'] ?? $right->getScore());

            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }

            $leftRank = min(
                (int) ($left->metadata['vector_rank'] ?? PHP_INT_MAX),
                (int) ($left->metadata['keyword_rank'] ?? PHP_INT_MAX),
            );
            $rightRank = min(
                (int) ($right->metadata['vector_rank'] ?? PHP_INT_MAX),
                (int) ($right->metadata['keyword_rank'] ?? PHP_INT_MAX),
            );

            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return ((int) ($left->metadata['chunk_id'] ?? $left->id)) <=> ((int) ($right->metadata['chunk_id'] ?? $right->id));
        });

        $mergedResults = array_slice($mergedResults, 0, $limit);

        foreach ($mergedResults as $index => $document) {
            $document->metadata['rank'] = $index + 1;
        }

        return $mergedResults;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function hydrateDocument(object $row, int $rank, float $score, array $metadata): Document
    {
        $decodedMetadata = $this->decodeMetadata($row->metadata ?? null);
        $document = new Document($row->content);
        $document->id = (int) $row->id;
        $document->sourceType = (string) ($decodedMetadata['source_type'] ?? 'document_chunk');
        $document->sourceName = (string) ($decodedMetadata['source_name'] ?? $row->document_id ?? $row->id);
        $document->metadata = [
            ...$decodedMetadata,
            'chunk_id' => (int) $row->id,
            'document_id' => isset($row->document_id) ? (int) $row->document_id : ($decodedMetadata['document_id'] ?? null),
            'document_version_id' => isset($row->document_version_id) ? (int) $row->document_version_id : ($decodedMetadata['document_version_id'] ?? null),
            'chunk_index' => isset($row->chunk_index) ? (int) $row->chunk_index : ($decodedMetadata['chunk_index'] ?? $rank - 1),
            'heading' => $row->heading ?? ($decodedMetadata['heading'] ?? null),
            'section_path' => $row->section_path ?? ($decodedMetadata['section_path'] ?? null),
            'page_number' => isset($row->page_number) ? (int) $row->page_number : ($decodedMetadata['page_number'] ?? null),
            'rank' => $rank,
            ...$metadata,
        ];
        $document->setScore($score);

        return $document;
    }
}
