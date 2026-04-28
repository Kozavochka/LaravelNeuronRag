@extends('layouts.admin')

@section('title', 'Document #' . $document->id)

@section('content')
    <div class="card">
        <h2>{{ $document->title }} <span class="badge {{ $document->status === 'failed' ? 'failed' : ($document->status === 'indexed' ? 'indexed' : '') }}">{{ $document->status }}</span></h2>
        <p class="muted">{{ $document->original_filename }} • ext={{ $document->extension }} • hash={{ $document->content_hash ?? 'n/a' }}</p>
        @if ($document->error_message)
            <div class="content-pre">{{ $document->error_message }}</div>
        @endif

        <div class="filters">
            <a href="{{ route('admin.documents.chunks', $document) }}">Open chunks</a>
            <a href="{{ route('admin.documents.versions', $document) }}">Open versions</a>
            <form method="POST" action="{{ route('admin.documents.reindex', $document) }}" class="inline">
                @csrf
                <button type="submit">Reindex</button>
            </form>
            <form method="POST" action="{{ route('admin.documents.destroy', $document) }}" class="inline" onsubmit="return confirm('Delete document and related chunks?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="danger">Delete</button>
            </form>
        </div>
    </div>

    <div class="grid cols-4">
        <div class="card"><div class="muted">Versions</div><div class="kpi">{{ $document->versions_count ?? 0 }}</div></div>
        <div class="card"><div class="muted">Total chunks</div><div class="kpi">{{ $document->chunks_count ?? 0 }}</div></div>
        <div class="card"><div class="muted">Active chunks</div><div class="kpi">{{ $document->active_chunks_count ?? 0 }}</div></div>
        <div class="card"><div class="muted">Updated</div><div class="kpi" style="font-size:16px">{{ $document->updated_at }}</div></div>
    </div>

    <div class="card">
        <h3>Recent versions</h3>
        <table>
            <thead><tr><th>ID</th><th>Status</th><th>Hash</th><th>Created</th></tr></thead>
            <tbody>
            @forelse ($document->versions as $version)
                <tr>
                    <td>{{ $version->id }}</td>
                    <td>{{ $version->status }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($version->version_hash, 32) }}</td>
                    <td>{{ $version->created_at }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">No versions yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
