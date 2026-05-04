<?php

declare(strict_types=1);

namespace App\Domain\Rag\Support;

final readonly class RagRuntimeConfig
{
    public function __construct(
        public string $openRouterApiKey,
        public string $openRouterBaseUrl,
        public string $openRouterModel,
        public string $openRouterAppName,
        public ?string $openRouterReferer,
        public string $embeddingProvider,
        public string $ollamaBaseUrl,
        public string $embeddingBaseUrl,
        public string $embeddingModel,
        public int $embeddingDimensions,
        public string $retrievalMode,
        public int $topK,
        public int $vectorCandidates,
        public int $keywordCandidates,
        public int $finalTopK,
        public int $rerankTopK,
        public float $vectorWeight,
        public float $keywordWeight,
        public string $tsDictionary,
        public int $maxContextChars,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self(
            openRouterApiKey: (string) (config('rag.llm.api_key') ?? env('OPENROUTER_API_KEY', '')),
            openRouterBaseUrl: rtrim((string) (config('rag.llm.base_url') ?? env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1')), '/'),
            openRouterModel: (string) (config('rag.llm.model') ?? env('OPENROUTER_MODEL', 'openrouter/auto')),
            openRouterAppName: (string) (config('rag.llm.app_name') ?? env('OPENROUTER_APP_NAME', config('app.name', 'Laravel RAG'))),
            openRouterReferer: self::nullableString(config('rag.llm.site_url') ?? env('OPENROUTER_SITE_URL') ?? env('OPENROUTER_HTTP_REFERER')),
            embeddingProvider: (string) (config('rag.embedding.provider') ?? env('RAG_EMBEDDING_PROVIDER', 'ollama')),
            ollamaBaseUrl: rtrim((string) env('OLLAMA_BASE_URL', 'http://localhost:11434'), '/'),
            embeddingBaseUrl: rtrim((string) (config('rag.embedding.base_url') ?? env('RAG_EMBEDDING_BASE_URL', env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'))), '/'),
            embeddingModel: (string) (config('rag.embedding.model') ?? env('RAG_EMBEDDING_MODEL', 'bge-m3')),
            embeddingDimensions: (int) (config('rag.embedding.dimensions') ?? env('RAG_EMBEDDING_DIMENSIONS', 1024)),
            retrievalMode: self::normalizeRetrievalMode((string) (config('rag.retrieval.mode') ?? env('RAG_RETRIEVAL_MODE', 'hybrid'))),
            topK: max(1, (int) (config('rag.retrieval.top_k') ?? env('RAG_TOP_K', 8))),
            vectorCandidates: max(1, (int) (config('rag.retrieval.vector_candidates') ?? env('RAG_VECTOR_CANDIDATES', 30))),
            keywordCandidates: max(1, (int) (config('rag.retrieval.keyword_candidates') ?? env('RAG_KEYWORD_CANDIDATES', 30))),
            finalTopK: max(1, (int) (config('rag.retrieval.final_top_k') ?? env('RAG_FINAL_TOP_K', env('RAG_RERANK_TOP_K', 5)))),
            rerankTopK: max(1, (int) (config('rag.retrieval.final_top_k') ?? config('rag.retrieval.rerank_top_k') ?? env('RAG_FINAL_TOP_K', env('RAG_RERANK_TOP_K', 5)))),
            vectorWeight: max(0.0, (float) (config('rag.retrieval.weights.vector') ?? env('RAG_VECTOR_WEIGHT', 0.7))),
            keywordWeight: max(0.0, (float) (config('rag.retrieval.weights.keyword') ?? env('RAG_KEYWORD_WEIGHT', 0.3))),
            tsDictionary: trim((string) (config('rag.retrieval.ts_dictionary') ?? env('RAG_TS_DICTIONARY', 'simple'))) ?: 'simple',
            maxContextChars: max(1, (int) (config('rag.retrieval.max_context_chars') ?? env('RAG_MAX_CONTEXT_CHARS', 16000))),
        );
    }

    private static function normalizeRetrievalMode(string $mode): string
    {
        return match (strtolower(trim($mode))) {
            'vector', 'keyword', 'hybrid' => strtolower(trim($mode)),
            default => 'hybrid',
        };
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
