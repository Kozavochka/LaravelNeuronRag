@extends('layouts.admin')

@section('title', 'RAG Queries')

@section('content')
    <div class="card">
        <form method="GET" class="filters">
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search question/answer">
            <select name="document_id">
                <option value="">document_id</option>
                @foreach ($documents as $doc)
                    <option value="{{ $doc->id }}" @selected((string) ($filters['document_id'] ?? '') === (string) $doc->id)>
                        {{ $doc->id }}: {{ $doc->title }}
                    </option>
                @endforeach
            </select>
            <select name="llm_model">
                <option value="">llm_model</option>
                @foreach ($models as $model)
                    <option value="{{ $model }}" @selected(($filters['llm_model'] ?? '') === $model)>{{ $model }}</option>
                @endforeach
            </select>
            <label>from <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
            <label>to <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
            <select name="sort">
                @foreach (['created_at','total_ms','total_tokens','estimated_cost_usd'] as $sort)
                    <option value="{{ $sort }}" @selected(($filters['sort'] ?? 'created_at') === $sort)>{{ $sort }}</option>
                @endforeach
            </select>
            <select name="dir">
                <option value="desc" @selected(($filters['dir'] ?? 'desc') === 'desc')>desc</option>
                <option value="asc" @selected(($filters['dir'] ?? 'desc') === 'asc')>asc</option>
            </select>
            <label><input type="checkbox" name="has_errors" value="1" @checked(($filters['has_errors'] ?? false) == true)> has_errors</label>
            <button type="submit">Apply</button>
        </form>
    </div>

    <div class="card">
        <table>
            <thead>
            <tr>
                <th>ID</th><th>Question</th><th>Model</th><th>Latency (ms)</th><th>Tokens</th><th>Cost USD</th><th>Created</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($queries as $query)
                <tr>
                    <td><a href="{{ route('admin.rag-queries.show', $query) }}">{{ $query->id }}</a></td>
                    <td>{{ \Illuminate\Support\Str::limit($query->question, 100) }}</td>
                    <td>{{ $query->llm_model ?? 'n/a' }}</td>
                    <td>{{ $query->total_ms ?? 'n/a' }}</td>
                    <td>{{ $query->total_tokens ?? 'n/a' }}</td>
                    <td>{{ $query->estimated_cost_usd ?? 'n/a' }}</td>
                    <td>{{ $query->created_at }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">No queries.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="pagination-wrap" style="margin-top:12px">{{ $queries->links('vendor.pagination.admin') }}</div>
    </div>
@endsection
