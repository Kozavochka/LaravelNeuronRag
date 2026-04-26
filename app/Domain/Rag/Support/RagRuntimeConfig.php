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
        public int $topK,
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
            topK: max(1, (int) (config('rag.retrieval.top_k') ?? env('RAG_TOP_K', 8))),
            maxContextChars: max(1, (int) (config('rag.retrieval.max_context_chars') ?? env('RAG_MAX_CONTEXT_CHARS', 16000))),
        );
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
