<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\RagQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $days = (int) ($validated['days'] ?? 7);
        $from = now()->subDays($days);

        $queryBase = RagQuery::query()->where('created_at', '>=', $from);

        $queryCount = (clone $queryBase)->count();
        $avgTokens = (float) ((clone $queryBase)->avg('total_tokens') ?? 0);
        $sumEstimatedCost = (string) ((clone $queryBase)->sum('estimated_cost_usd') ?? '0');

        $withDocumentCount = (clone $queryBase)
            ->where('metadata', 'like', '%"document_id":%')
            ->count();

        $documentFilterShare = $queryCount > 0
            ? round(($withDocumentCount / $queryCount) * 100, 2)
            : 0.0;

        $latencies = (clone $queryBase)
            ->whereNotNull('total_ms')
            ->orderBy('total_ms')
            ->pluck('total_ms')
            ->map(static fn ($value): int => (int) $value)
            ->values()
            ->all();

        $p50 = $this->percentile($latencies, 50);
        $p95 = $this->percentile($latencies, 95);

        $documentsByStatus = Document::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $recentQueries = RagQuery::query()
            ->latest('id')
            ->limit(10)
            ->get(['id', 'question', 'llm_model', 'total_ms', 'total_tokens', 'estimated_cost_usd', 'created_at']);

        return view('admin.dashboard', [
            'days' => $days,
            'queryCount' => $queryCount,
            'avgTokens' => $avgTokens,
            'sumEstimatedCost' => $sumEstimatedCost,
            'documentFilterShare' => $documentFilterShare,
            'p50' => $p50,
            'p95' => $p95,
            'documentsByStatus' => $documentsByStatus,
            'recentQueries' => $recentQueries,
        ]);
    }

    /**
     * @param array<int, int> $sortedValues
     */
    private function percentile(array $sortedValues, int $percentile): ?int
    {
        if ($sortedValues === []) {
            return null;
        }

        $position = (($percentile / 100) * (count($sortedValues) - 1));
        $lowerIndex = (int) floor($position);
        $upperIndex = (int) ceil($position);

        if ($lowerIndex === $upperIndex) {
            return $sortedValues[$lowerIndex];
        }

        $weight = $position - $lowerIndex;

        return (int) round($sortedValues[$lowerIndex] + (($sortedValues[$upperIndex] - $sortedValues[$lowerIndex]) * $weight));
    }
}
