@extends('layouts.admin')

@section('title', 'Document Chunks')

@section('content')
    <div class="card">
        <h2>Chunks for <a href="{{ route('admin.documents.show', $document) }}">{{ $document->title }}</a></h2>
        <form method="GET" class="filters">
            <input type="text" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="search content/heading/section">
            <label><input type="checkbox" name="active" value="1" @checked(!array_key_exists('active', $filters) || (bool) ($filters['active'] ?? true))> only active</label>
            <button type="submit">Apply</button>
        </form>

        <table>
            <thead><tr><th>#</th><th>Heading / section</th><th>Chars</th><th>Active</th><th>Content</th></tr></thead>
            <tbody>
            @forelse ($chunks as $chunk)
                <tr>
                    <td>{{ $chunk->chunk_index }}</td>
                    <td>
                        <div>{{ $chunk->heading ?? 'n/a' }}</div>
                        <div class="muted">{{ $chunk->section_path ?? 'n/a' }}</div>
                    </td>
                    <td>{{ $chunk->char_count ?? 'n/a' }}</td>
                    <td>{{ $chunk->is_active ? 'yes' : 'no' }}</td>
                    <td><div class="content-pre">{{ \Illuminate\Support\Str::limit($chunk->content, 800) }}</div></td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No chunks found.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="pagination-wrap" style="margin-top:12px">{{ $chunks->links('vendor.pagination.admin') }}</div>
    </div>
@endsection
