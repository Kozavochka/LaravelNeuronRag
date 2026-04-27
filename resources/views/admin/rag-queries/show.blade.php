@extends('layouts.admin')

@section('title', 'RAG Query #' . $query->id)

@section('content')
    <div class="card">
        <h2>Query #{{ $query->id }}</h2>
        <div class="muted">created {{ $query->created_at }} • model {{ $query->llm_model ?? 'n/a' }} • embedding {{ $query->embedding_model ?? 'n/a' }}</div>
    </div>

    <div class="grid cols-2">
        <div class="card">
            <h3>Request/response</h3>
            <p><strong>Question</strong></p>
            <div class="content-pre">{{ $query->question }}</div>
            <p><strong>Answer</strong></p>
            <div class="content-pre">{{ $query->answer ?? 'n/a' }}</div>
            <p class="muted">document_id filter: {{ $query->metadata['document_id'] ?? 'none' }}</p>
        </div>

        <div class="card">
            <h3>Telemetry</h3>
            <table>
                <tbody>
                <tr><th>top_k</th><td>{{ $query->top_k ?? 'n/a' }}</td></tr>
                <tr><th>embedding_ms</th><td>{{ $query->embedding_ms ?? 'n/a' }}</td></tr>
                <tr><th>vector_search_ms</th><td>{{ $query->vector_search_ms ?? 'n/a' }}</td></tr>
                <tr><th>rerank_ms</th><td>{{ $query->rerank_ms ?? 'n/a' }}</td></tr>
                <tr><th>prompt_build_ms</th><td>{{ $query->prompt_build_ms ?? 'n/a' }}</td></tr>
                <tr><th>llm_ms</th><td>{{ $query->llm_ms ?? 'n/a' }}</td></tr>
                <tr><th>total_ms</th><td>{{ $query->total_ms ?? 'n/a' }}</td></tr>
                <tr><th>prompt_tokens</th><td>{{ $query->prompt_tokens ?? 'n/a' }}</td></tr>
                <tr><th>completion_tokens</th><td>{{ $query->completion_tokens ?? 'n/a' }}</td></tr>
                <tr><th>total_tokens</th><td>{{ $query->total_tokens ?? 'n/a' }}</td></tr>
                <tr><th>estimated_cost_usd</th><td>{{ $query->estimated_cost_usd ?? 'n/a' }}</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <h3>Retrieved sources</h3>
        <table>
            <thead><tr><th>rank</th><th>chunk_id</th><th>document</th><th>distance</th><th>score</th><th>rerank_score</th><th>vector_rank</th><th>rank_after_rerank</th></tr></thead>
            <tbody>
            @forelse ($sources as $source)
                <tr>
                    <td>{{ $source['rank'] ?? 'n/a' }}</td>
                    <td>{{ $source['chunk_id'] ?? 'n/a' }}</td>
                    <td>
                        @if (isset($source['document_id']) && isset($documents[$source['document_id']]))
                            <a href="{{ route('admin.documents.show', $documents[$source['document_id']]) }}">{{ $source['document_id'] }}: {{ $documents[$source['document_id']]->title }}</a>
                        @else
                            {{ $source['document_id'] ?? 'n/a' }}
                        @endif
                    </td>
                    <td>{{ $source['distance'] ?? 'n/a' }}</td>
                    <td>{{ $source['score'] ?? 'n/a' }}</td>
                    <td>{{ $source['rerank_score'] ?? 'n/a' }}</td>
                    <td>{{ $source['vector_rank'] ?? 'n/a' }}</td>
                    <td>{{ $source['rank_after_rerank'] ?? 'n/a' }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">No source entries in metadata.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="card">
        <h3>rag_query_chunks rows</h3>
        <table>
            <thead><tr><th>rank</th><th>chunk_id</th><th>distance</th><th>score</th><th>rerank_score</th></tr></thead>
            <tbody>
            @forelse ($queryChunks as $row)
                <tr>
                    <td>{{ $row->rank ?? 'n/a' }}</td>
                    <td>{{ $row->document_chunk_id }}</td>
                    <td>{{ $row->distance ?? 'n/a' }}</td>
                    <td>{{ $row->score ?? 'n/a' }}</td>
                    <td>{{ $row->rerank_score ?? 'n/a' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No persisted chunk links.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
