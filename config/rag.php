<?php

declare(strict_types=1);

$toBool = static fn (mixed $value, bool $default): bool => filter_var(
    $value ?? $default,
    FILTER_VALIDATE_BOOL,
    FILTER_NULL_ON_FAILURE,
) ?? $default;

return [
    'documents' => [
        'disk' => env('RAG_DOCUMENTS_DISK', 'local'),
        'directory' => env('RAG_DOCUMENTS_DIRECTORY', 'rag/documents'),
        'allowed_extensions' => ['md', 'docx'],
        'max_upload_kb' => (int) env('RAG_MAX_UPLOAD_KB', 10240),
    ],

    'embedding' => [
        'provider' => env('RAG_EMBEDDING_PROVIDER', 'ollama'),
        'model' => env('RAG_EMBEDDING_MODEL', 'bge-m3'),
        'dimensions' => (int) env('RAG_EMBEDDING_DIMENSIONS', 1024),
        'base_url' => env('RAG_EMBEDDING_BASE_URL', env('OLLAMA_BASE_URL', 'http://127.0.0.1:11434')),
        'timeout' => (int) env('RAG_EMBEDDING_TIMEOUT', 60),
    ],

    'llm' => [
        'provider' => env('RAG_LLM_PROVIDER', 'openrouter'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'api_key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'openrouter/auto'),
        'site_url' => env('OPENROUTER_SITE_URL', env('APP_URL', 'http://localhost')),
        'app_name' => env('OPENROUTER_APP_NAME', env('APP_NAME', 'Laravel RAG')),
        'timeout' => (int) env('RAG_LLM_TIMEOUT', 90),
    ],

    'chunking' => [
        'size_chars' => (int) env('RAG_CHUNK_SIZE_CHARS', 3200),
        'overlap_chars' => (int) env('RAG_CHUNK_OVERLAP_CHARS', 500),
        'min_chunk_chars' => (int) env('RAG_MIN_CHUNK_CHARS', 200),
        'max_chunks_per_document' => (int) env('RAG_MAX_CHUNKS_PER_DOCUMENT', 500),
        'strip_html_comments' => $toBool(env('RAG_STRIP_HTML_COMMENTS', true), true),
        'preserve_headings' => $toBool(env('RAG_PRESERVE_HEADINGS', true), true),
    ],

    'retrieval' => [
        'top_k' => (int) env('RAG_TOP_K', 8),
        'max_context_chars' => (int) env('RAG_MAX_CONTEXT_CHARS', 16000),
        'min_score' => (float) env('RAG_MIN_SCORE', 0.0),
        'document_filter_enabled' => $toBool(env('RAG_DOCUMENT_FILTER_ENABLED', true), true),
    ],

    'http' => [
        'pagination' => [
            'per_page' => (int) env('RAG_API_PER_PAGE', 15),
            'max_per_page' => (int) env('RAG_API_MAX_PER_PAGE', 100),
        ],
        'runtime_available' => $toBool(env('RAG_RUNTIME_AVAILABLE', false), false),
    ],

    'costs' => [
        'models' => [
            'openrouter/auto' => [
                'input_per_1m' => (float) env('RAG_COST_OPENROUTER_AUTO_INPUT_PER_1M', 0),
                'output_per_1m' => (float) env('RAG_COST_OPENROUTER_AUTO_OUTPUT_PER_1M', 0),
            ],
        ],
    ],
];
