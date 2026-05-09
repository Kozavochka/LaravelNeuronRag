@extends('layouts.admin')

@section('title', 'Upload Document')

@section('content')
    <div class="card">
        <h2>Upload document</h2>
        <p class="muted">Supported extensions: {{ implode(', ', $allowedExtensions) }}</p>
        <p class="muted">
            MarkItDown health:
            <span class="badge {{ $markitdownHealth->isAvailable ? 'indexed' : 'failed' }}">{{ $markitdownHealth->status }}</span>
            (base: {{ implode(', ', config('rag.documents.allowed_extensions', ['md', 'docx'])) }})
        </p>

        <form method="POST" action="{{ route('admin.documents.store') }}" enctype="multipart/form-data" class="filters">
            @csrf
            <input type="file" name="file" required>
            <button type="submit">Upload + Queue Indexing</button>
        </form>
    </div>
@endsection
