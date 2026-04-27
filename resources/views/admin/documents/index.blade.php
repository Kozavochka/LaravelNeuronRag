@extends('layouts.admin')

@section('title', 'Documents')

@section('content')
    <div class="card">
        <form method="GET" class="filters">
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search title/file">
            <input type="text" name="status" value="{{ $filters['status'] ?? '' }}" placeholder="status">
            <label>from <input type="date" name="date_from" value="{{ $filters['date_from'] ?? '' }}"></label>
            <label>to <input type="date" name="date_to" value="{{ $filters['date_to'] ?? '' }}"></label>
            <select name="sort">
                @foreach (['created_at','updated_at','status','title'] as $sort)
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
                <th>ID</th><th>Title</th><th>Status</th><th>Chunks</th><th>Versions</th><th>Updated</th><th>Actions</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($documents as $document)
                <tr>
                    <td>{{ $document->id }}</td>
                    <td>
                        <a href="{{ route('admin.documents.show', $document) }}">{{ $document->title }}</a>
                        <div class="muted">{{ $document->original_filename }}</div>
                    </td>
                    <td>
                        <span class="badge {{ $document->status === 'failed' ? 'failed' : ($document->status === 'indexed' ? 'indexed' : '') }}">{{ $document->status }}</span>
                        @if ($document->error_message)
                            <div class="muted">{{ \Illuminate\Support\Str::limit($document->error_message, 60) }}</div>
                        @endif
                    </td>
                    <td>{{ $document->active_chunks_count ?? 0 }} / {{ $document->chunks_count ?? 0 }}</td>
                    <td>{{ $document->versions_count ?? 0 }}</td>
                    <td>{{ $document->updated_at }}</td>
                    <td>
                        <a href="{{ route('admin.documents.chunks', $document) }}">chunks</a> |
                        <a href="{{ route('admin.documents.versions', $document) }}">versions</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">No documents.</td></tr>
            @endforelse
            </tbody>
        </table>

        <div class="pagination-wrap" style="margin-top:12px">{{ $documents->links('vendor.pagination.admin') }}</div>
    </div>
@endsection
