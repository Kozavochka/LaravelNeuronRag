<?php

namespace App\Providers;

use App\Domain\Documents\Services\TextExtraction\DocxTextExtractor;
use App\Domain\Documents\Services\TextExtraction\MarkdownTextExtractor;
use App\Domain\Documents\Services\TextExtraction\TextExtractorFactory;
use App\Domain\Rag\PostProcessors\LimitContextPostProcessor;
use App\Domain\Rag\PostProcessors\RerankPostProcessor;
use App\Domain\Rag\Contracts\RerankerInterface;
use App\Domain\Rag\Services\CostEstimator;
use App\Domain\Rag\Services\RagChatRuntime;
use App\Domain\Rag\Services\RagQueryLogger;
use App\Domain\Rag\Services\SimpleKeywordReranker;
use App\Domain\Rag\Services\Telemetry\RagQueryTelemetry;
use App\Domain\Rag\Services\Telemetry\TelemetryEmbeddingsProvider;
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
        $this->app->singleton(RagQueryTelemetry::class, static fn (): RagQueryTelemetry => new RagQueryTelemetry());
        $this->app->singleton(RerankerInterface::class, static function (): RerankerInterface {
            return new SimpleKeywordReranker(
                contentWeight: (float) config('rag.retrieval.rerank.content_weight', 0.03),
                headingWeight: (float) config('rag.retrieval.rerank.heading_weight', 0.05),
                sectionPathWeight: (float) config('rag.retrieval.rerank.section_path_weight', 0.04),
                minTokenLen: max(1, (int) config('rag.retrieval.rerank.min_token_len', 2)),
            );
        });
        $this->app->singleton(CostEstimator::class);

        $this->app->singleton(EmbeddingsProviderInterface::class, fn ($app): EmbeddingsProviderInterface => $this->makeEmbeddingsProvider(
            $app->make(RagRuntimeConfig::class),
        ));

        $this->app->bind(PgVectorStore::class, function ($app): PgVectorStore {
            $config = $app->make(RagRuntimeConfig::class);

            return new PgVectorStore(
                defaultTopK: $config->topK,
                telemetry: $app->make(RagQueryTelemetry::class),
            );
        });

        $this->app->bind(LimitContextPostProcessor::class, function ($app): LimitContextPostProcessor {
            $config = $app->make(RagRuntimeConfig::class);

            return new LimitContextPostProcessor($config->maxContextChars);
        });
        $this->app->bind(RerankPostProcessor::class, function ($app): RerankPostProcessor {
            $config = $app->make(RagRuntimeConfig::class);

            return new RerankPostProcessor(
                reranker: $app->make(RerankerInterface::class),
                telemetry: $app->make(RagQueryTelemetry::class),
                defaultFinalTopK: $config->rerankTopK,
            );
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
                runtimeEmbeddingsProvider: new TelemetryEmbeddingsProvider(
                    provider: $this->makeEmbeddingsProvider($config),
                    telemetry: $app->make(RagQueryTelemetry::class),
                ),
                runtimeVectorStore: $app->make(PgVectorStore::class),
                runtimeRetrievedDocumentBuffer: $app->make(RetrievedDocumentBuffer::class),
                runtimeRerankPostProcessor: $app->make(RerankPostProcessor::class),
                runtimeLimitContextPostProcessor: $app->make(LimitContextPostProcessor::class),
                defaultVectorCandidates: $config->vectorCandidates,
                defaultRerankTopK: $config->rerankTopK,
            );
        });

        $this->app->bind(RagChatRuntime::class, function ($app): RagChatRuntime {
            return new RagChatRuntime(
                queryLogger: $app->make(RagQueryLogger::class),
                rag: $app->make(DocumentRAG::class),
                telemetry: $app->make(RagQueryTelemetry::class),
                costEstimator: $app->make(CostEstimator::class),
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
