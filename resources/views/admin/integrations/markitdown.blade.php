@extends('layouts.admin')

@section('title', 'MarkItDown Integration')

@section('content')
    <div class="card">
        <h2>MarkItDown</h2>
        <p class="muted">Status: <span class="badge {{ $health->isAvailable ? 'indexed' : 'failed' }}">{{ $health->status }}</span></p>
        <p class="muted">Allowed extensions now: {{ implode(', ', $allowedExtensions) }}</p>
        <p class="muted">Base: {{ implode(', ', $baseExtensions) }}</p>
        <p class="muted">Extended: {{ implode(', ', $extendedExtensions) }}</p>
    </div>

    <div class="card">
        <h3>Recent events</h3>
        <table>
            <thead><tr><th>ID</th><th>Type</th><th>Status</th><th>Latency ms</th><th>Message</th><th>Created</th></tr></thead>
            <tbody>
            @forelse ($events as $event)
                <tr>
                    <td>{{ $event->id }}</td>
                    <td>{{ $event->event_type }}</td>
                    <td>{{ $event->status_code }}</td>
                    <td>{{ $event->latency_ms }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($event->message ?? '', 120) }}</td>
                    <td>{{ $event->created_at }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No events yet.</td></tr>
            @endforelse
            </tbody>
        </table>

        <div class="pagination-wrap" style="margin-top:12px">{{ $events->links('vendor.pagination.admin') }}</div>
    </div>
@endsection
