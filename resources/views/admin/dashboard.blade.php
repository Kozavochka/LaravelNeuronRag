@extends('layouts.admin')

@section('title', 'RAG Dashboard')

@section('content')
    <div class="card">
        <form method="GET" class="filters">
            <label>Days <input type="number" name="days" min="1" max="365" value="{{ $days }}"></label>
            <button type="submit">Apply</button>
        </form>
    </div>

    <div class="grid cols-4">
        <div class="card"><div class="muted">Queries ({{ $days }}d)</div><div class="kpi">{{ $queryCount }}</div></div>
        <div class="card"><div class="muted">Latency p50 / p95 (ms)</div><div class="kpi">{{ $p50 ?? 'n/a' }} / {{ $p95 ?? 'n/a' }}</div></div>
        <div class="card"><div class="muted">Avg tokens/query</div><div class="kpi">{{ number_format($avgTokens, 2) }}</div></div>
        <div class="card"><div class="muted">Estimated cost USD</div><div class="kpi">{{ number_format((float) $sumEstimatedCost, 8) }}</div></div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>Usage diagnostics</h3>
            <p class="muted">Document filter usage share: <strong>{{ number_format($documentFilterShare, 2) }}%</strong></p>
            <table>
                <thead><tr><th>Status</th><th>Total documents</th></tr></thead>
                <tbody>
                @foreach ($documentsByStatus as $status => $total)
                    <tr><td>{{ $status }}</td><td>{{ $total }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h3>Recent queries</h3>
            <table>
                <thead><tr><th>ID</th><th>Question</th><th>total_ms</th><th>tokens</th><th>cost</th></tr></thead>
                <tbody>
                @forelse ($recentQueries as $query)
                    <tr>
                        <td><a href="{{ route('admin.rag-queries.show', $query) }}">{{ $query->id }}</a></td>
                        <td>{{ \Illuminate\Support\Str::limit($query->question, 80) }}</td>
                        <td>{{ $query->total_ms ?? 'n/a' }}</td>
                        <td>{{ $query->total_tokens ?? 'n/a' }}</td>
                        <td>{{ $query->estimated_cost_usd ?? 'n/a' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No data for selected period.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
