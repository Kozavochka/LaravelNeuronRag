<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

final class CostEstimator
{
    public function estimate(string $model, ?int $promptTokens, ?int $completionTokens): ?string
    {
        $pricing = config("rag.costs.models.{$model}");

        if (! is_array($pricing)) {
            return null;
        }

        $inputPerMillion = (float) ($pricing['input_per_1m'] ?? 0);
        $outputPerMillion = (float) ($pricing['output_per_1m'] ?? 0);

        $cost = (($promptTokens ?? 0) / 1_000_000 * $inputPerMillion)
            + (($completionTokens ?? 0) / 1_000_000 * $outputPerMillion);

        return number_format($cost, 8, '.', '');
    }
}
