@extends('layouts.admin')

@section('title', 'Document Versions')

@section('content')
    <div class="card">
        <h2>Versions for <a href="{{ route('admin.documents.show', $document) }}">{{ $document->title }}</a></h2>
        <table>
            <thead><tr><th>ID</th><th>Status</th><th>Version hash</th><th>Chunks</th><th>Created</th></tr></thead>
            <tbody>
            @forelse ($versions as $version)
                <tr>
                    <td>{{ $version->id }}</td>
                    <td>{{ $version->status }}</td>
                    <td>{{ $version->version_hash }}</td>
                    <td>{{ $version->chunks_count ?? 0 }}</td>
                    <td>{{ $version->created_at }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No versions.</td></tr>
            @endforelse
            </tbody>
        </table>
        <div class="pagination-wrap" style="margin-top:12px">{{ $versions->links('vendor.pagination.admin') }}</div>
    </div>
@endsection
