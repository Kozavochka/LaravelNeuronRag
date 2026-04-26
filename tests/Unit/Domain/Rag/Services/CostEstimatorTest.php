<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Rag\Services;

use App\Domain\Rag\Services\CostEstimator;
use Tests\TestCase;

class CostEstimatorTest extends TestCase
{
    public function test_returns_null_when_model_pricing_is_missing(): void
    {
        config()->set('rag.costs.models', []);

        $estimator = new CostEstimator();

        $this->assertNull($estimator->estimate('missing/model', 1000, 1000));
    }

    public function test_returns_zero_string_when_pricing_exists_and_is_zero(): void
    {
        config()->set('rag.costs.models.test/free', [
            'input_per_1m' => 0,
            'output_per_1m' => 0,
        ]);

        $estimator = new CostEstimator();

        $this->assertSame('0.00000000', $estimator->estimate('test/free', 1000, 1000));
    }

    public function test_calculates_cost_from_prompt_and_completion_tokens(): void
    {
        config()->set('rag.costs.models.test/paid', [
            'input_per_1m' => 2.5,
            'output_per_1m' => 10.0,
        ]);

        $estimator = new CostEstimator();

        $this->assertSame('0.01250000', $estimator->estimate('test/paid', 1_000, 1_000));
    }
}
