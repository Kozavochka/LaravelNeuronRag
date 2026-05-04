<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Rag\Support;

use App\Domain\Rag\Support\RagRuntimeConfig;
use Tests\TestCase;

final class RagRuntimeConfigTest extends TestCase
{
    public function test_it_reads_hybrid_retrieval_settings_from_config(): void
    {
        config()->set('rag.retrieval.mode', 'keyword');
        config()->set('rag.retrieval.keyword_candidates', 17);
        config()->set('rag.retrieval.final_top_k', 9);
        config()->set('rag.retrieval.weights.vector', 0.6);
        config()->set('rag.retrieval.weights.keyword', 0.4);
        config()->set('rag.retrieval.ts_dictionary', 'simple');

        $config = RagRuntimeConfig::fromConfig();

        $this->assertSame('keyword', $config->retrievalMode);
        $this->assertSame(17, $config->keywordCandidates);
        $this->assertSame(9, $config->finalTopK);
        $this->assertSame(9, $config->rerankTopK);
        $this->assertSame(0.6, $config->vectorWeight);
        $this->assertSame(0.4, $config->keywordWeight);
        $this->assertSame('simple', $config->tsDictionary);
    }
}
