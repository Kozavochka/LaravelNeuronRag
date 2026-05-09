<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'RAG Admin')</title>
    <style>
        :root { --bg:#f6f7fb; --card:#fff; --text:#111827; --muted:#6b7280; --line:#e5e7eb; --accent:#0f766e; --danger:#b91c1c; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: ui-sans-serif, -apple-system, Segoe UI, sans-serif; color:var(--text); background:linear-gradient(180deg,#f8fafc 0%,#eef2ff 100%); }
        a { color:#0f766e; text-decoration:none; }
        a:hover { text-decoration:underline; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .nav { display:flex; gap:16px; align-items:center; margin-bottom:20px; }
        .nav a { padding:8px 10px; border-radius:8px; background:#fff; border:1px solid var(--line); }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:16px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,0.04); }
        .grid { display:grid; gap:12px; }
        .grid.cols-4 { grid-template-columns: repeat(4, minmax(0,1fr)); }
        .grid.cols-2 { grid-template-columns: repeat(2, minmax(0,1fr)); }
        .kpi { font-size: 24px; font-weight: 700; margin-top:8px; }
        .muted { color: var(--muted); font-size: 13px; }
        .badge { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; border:1px solid var(--line); background:#f9fafb; }
        .badge.failed { color:var(--danger); border-color:#fecaca; background:#fef2f2; }
        .badge.indexed { color:#166534; border-color:#bbf7d0; background:#f0fdf4; }
        table { width:100%; border-collapse: collapse; font-size:14px; }
        th, td { border-bottom:1px solid var(--line); padding:10px 8px; text-align:left; vertical-align:top; }
        th { color:#374151; font-weight:600; }
        form.inline { display:inline; }
        input, select { border:1px solid #d1d5db; border-radius:8px; padding:8px; background:#fff; }
        button { border:1px solid #0f766e; color:#fff; background:#0f766e; border-radius:8px; padding:8px 12px; cursor:pointer; }
        button.secondary { border-color:#9ca3af; background:#fff; color:#111827; }
        button.danger { border-color:#b91c1c; background:#b91c1c; }
        .filters { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
        .content-pre { white-space:pre-wrap; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:13px; background:#f8fafc; border:1px solid var(--line); border-radius:8px; padding:10px; }
        .admin-pager { display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; font-size:14px; }
        .admin-pager-meta { color:#374151; }
        .admin-pager-links { display:flex; align-items:center; gap:6px; }
        .admin-pager-links a, .admin-pager-links span { display:inline-flex; align-items:center; justify-content:center; min-width:34px; height:34px; padding:0 10px; border:1px solid var(--line); border-radius:8px; background:#fff; color:var(--text); text-decoration:none; }
        .admin-pager-links .is-current { background:#0f766e; border-color:#0f766e; color:#fff; }
        .admin-pager-links .is-disabled, .admin-pager-links .is-ellipsis { color:var(--muted); background:#f9fafb; }
        @media (max-width: 900px) { .grid.cols-4, .grid.cols-2 { grid-template-columns: 1fr; } table { font-size:13px; } }
    </style>
</head>
<body>
<div class="container">
    <div class="nav">
        <a href="{{ route('admin.dashboard') }}">Dashboard</a>
        <a href="{{ route('admin.documents.index') }}">Documents</a>
        <a href="{{ route('admin.documents.create') }}">Upload</a>
        <a href="{{ route('admin.rag-queries.index') }}">RAG Queries</a>
        <a href="{{ route('admin.integrations.markitdown') }}">Integrations</a>
    </div>

    @if (session('status'))
        <div class="card">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="card">
            <strong>Validation errors:</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</div>
</body>
</html>
