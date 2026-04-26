<?php

namespace App\Providers;

use App\Domain\Documents\Services\TextExtraction\DocxTextExtractor;
use App\Domain\Documents\Services\TextExtraction\MarkdownTextExtractor;
use App\Domain\Documents\Services\TextExtraction\TextExtractorFactory;
use App\Domain\Rag\PostProcessors\LimitContextPostProcessor;
use App\Domain\Rag\Services\RagChatRuntime;
use App\Domain\Rag\Services\RagQueryLogger;
use App\Domain\Rag\Support\RagRuntimeConfig;
use App\Domain\Rag\Support\RetrievedDocumentBuffer;
use App\Neuron\DocumentRAG;
use App\Neuron\VectorStore\PgVectorStore;
use Illuminate\Support\ServiceProvider;
use NeuronAI\HttpClient\GuzzleHttpClient;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\Providers\OpenAILike;
use NeuronAI\RAG\Embeddings\OllamaEmbeddingsProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(RagRuntimeConfig::class, static fn (): RagRuntimeConfig => RagRuntimeConfig::fromConfig());

        $this->app->singleton(TextExtractorFactory::class, static fn (): TextExtractorFactory => new TextExtractorFactory([
            new MarkdownTextExtractor(),
            new DocxTextExtractor(),
        ]));

        $this->app->bind(RetrievedDocumentBuffer::class, static fn (): RetrievedDocumentBuffer => new RetrievedDocumentBuffer());

        $this->app->singleton(EmbeddingsProviderInterface::class, fn ($app): EmbeddingsProviderInterface => $this->makeEmbeddingsProvider(
            $app->make(RagRuntimeConfig::class),
        ));

        $this->app->bind(PgVectorStore::class, function ($app): PgVectorStore {
            $config = $app->make(RagRuntimeConfig::class);

            return new PgVectorStore($config->topK);
        });

        $this->app->bind(LimitContextPostProcessor::class, function ($app): LimitContextPostProcessor {
            $config = $app->make(RagRuntimeConfig::class);

            return new LimitContextPostProcessor($config->maxContextChars);
        });

        $this->app->singleton(RagQueryLogger::class);

        $this->app->bind(DocumentRAG::class, function ($app): DocumentRAG {
            $config = $app->make(RagRuntimeConfig::class);

            $httpClient = (new GuzzleHttpClient())->withHeaders(array_filter([
                'HTTP-Referer' => $config->openRouterReferer,
                'X-Title' => $config->openRouterAppName,
            ]));

            $provider = new OpenAILike(
                baseUri: $config->openRouterBaseUrl,
                key: $config->openRouterApiKey,
                model: $config->openRouterModel,
                parameters: [
                    'temperature' => 0.1,
                ],
                httpClient: $httpClient,
            );

            return new DocumentRAG(
                runtimeAiProvider: $provider,
                runtimeEmbeddingsProvider: $this->makeEmbeddingsProvider($config),
                runtimeVectorStore: $app->make(PgVectorStore::class),
                runtimeRetrievedDocumentBuffer: $app->make(RetrievedDocumentBuffer::class),
                runtimeLimitContextPostProcessor: $app->make(LimitContextPostProcessor::class),
            );
        });

        $this->app->bind(RagChatRuntime::class, function ($app): RagChatRuntime {
            return new RagChatRuntime(
                queryLogger: $app->make(RagQueryLogger::class),
                rag: $app->make(DocumentRAG::class),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    private function makeEmbeddingsProvider(RagRuntimeConfig $config): EmbeddingsProviderInterface
    {
        return match ($config->embeddingProvider) {
            'openrouter', 'openai-like', 'openai_like' => new OpenAILikeEmbeddings(
                baseUri: $config->embeddingBaseUrl,
                key: $config->openRouterApiKey,
                model: $config->embeddingModel,
                dimensions: $config->embeddingDimensions,
            ),
            default => new OllamaEmbeddingsProvider(
                model: $config->embeddingModel,
                url: rtrim($config->ollamaBaseUrl, '/') . '/api',
            ),
        };
    }
}
