# Проектное решение: Blade-админка для управления RAG-документами

## 1. Цель

Добавить в LaravelNeuronRag простую админку на Blade, чтобы через UI можно было:

```text
1. Смотреть список загруженных документов.
2. Загружать новые .md и .docx файлы.
3. Смотреть карточку документа.
4. Смотреть версии документа.
5. Смотреть чанки, на которые документ разбился.
6. Смотреть metadata чанков.
7. Удалять документы.
8. Запускать реиндексацию.
9. Смотреть ошибки индексации.
10. Смотреть RAG-запросы и какие чанки использовались в ответе.
```

Blade-админка нужна не только для управления документами, но и для отладки качества RAG.

Главная польза:

```text
Если RAG плохо отвечает, первым делом нужно посмотреть:
- как документ разбился на чанки;
- какие metadata сохранились;
- какие чанки попали в prompt;
- были ли ошибки индексации.
```

---

## 2. Текущая основа проекта

В проекте уже есть подходящие модели:

```text
Document
DocumentVersion
DocumentChunk
RagQuery
RagQueryChunk
```

Логическая схема:

```text
Document
  → DocumentVersion[]
  → DocumentChunk[]

RagQuery
  → RagQueryChunk[]
  → DocumentChunk
```

Для админки это уже достаточно.

---

## 3. Основные страницы админки

Минимальный набор страниц:

```text
GET  /admin/documents
GET  /admin/documents/create
POST /admin/documents

GET  /admin/documents/{document}
GET  /admin/documents/{document}/versions
GET  /admin/documents/{document}/chunks

POST /admin/documents/{document}/reindex
DELETE /admin/documents/{document}

GET  /admin/rag-queries
GET  /admin/rag-queries/{ragQuery}
```

---

## 4. Структура файлов

```text
app/
  Http/
    Controllers/
      Admin/
        AdminDocumentController.php
        AdminRagQueryController.php

resources/
  views/
    layouts/
      admin.blade.php

    admin/
      documents/
        index.blade.php
        create.blade.php
        show.blade.php
        chunks.blade.php
        versions.blade.php

      rag-queries/
        index.blade.php
        show.blade.php
```

---

## 5. Routes

Добавить в `routes/web.php`.

```php
<?php

use App\Http\Controllers\Admin\AdminDocumentController;
use App\Http\Controllers\Admin\AdminRagQueryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin/documents');
});

Route::prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/documents', [AdminDocumentController::class, 'index'])
            ->name('documents.index');

        Route::get('/documents/create', [AdminDocumentController::class, 'create'])
            ->name('documents.create');

        Route::post('/documents', [AdminDocumentController::class, 'store'])
            ->name('documents.store');

        Route::get('/documents/{document}', [AdminDocumentController::class, 'show'])
            ->name('documents.show');

        Route::get('/documents/{document}/chunks', [AdminDocumentController::class, 'chunks'])
            ->name('documents.chunks');

        Route::get('/documents/{document}/versions', [AdminDocumentController::class, 'versions'])
            ->name('documents.versions');

        Route::post('/documents/{document}/reindex', [AdminDocumentController::class, 'reindex'])
            ->name('documents.reindex');

        Route::delete('/documents/{document}', [AdminDocumentController::class, 'destroy'])
            ->name('documents.destroy');

        Route::get('/rag-queries', [AdminRagQueryController::class, 'index'])
            ->name('rag-queries.index');

        Route::get('/rag-queries/{ragQuery}', [AdminRagQueryController::class, 'show'])
            ->name('rag-queries.show');
    });
```

---

## 6. AdminDocumentController

Создать:

```text
app/Http/Controllers/Admin/AdminDocumentController.php
```

Пример:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Documents\Services\DocumentImportService;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessDocumentJob;
use App\Jobs\ReindexDocumentJob;
use App\Models\Document;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class AdminDocumentController extends Controller
{
    public function index(): View
    {
        $documents = Document::query()
            ->withCount([
                'versions',
                'chunks',
                'activeChunks',
            ])
            ->latest()
            ->paginate(20);

        return view('admin.documents.index', [
            'documents' => $documents,
        ]);
    }

    public function create(): View
    {
        return view('admin.documents.create');
    }

    public function store(
        Request $request,
        DocumentImportService $importService,
    ): RedirectResponse {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:md,docx',
                'max:20480',
            ],
        ]);

        $document = $importService->import($validated['file']);

        ProcessDocumentJob::dispatch($document->id);

        return redirect()
            ->route('admin.documents.show', $document)
            ->with('status', 'Документ загружен и отправлен на индексацию.');
    }

    public function show(Document $document): View
    {
        $document->loadCount([
            'versions',
            'chunks',
            'activeChunks',
        ]);

        $document->load([
            'versions' => fn ($query) => $query->latest()->limit(5),
        ]);

        return view('admin.documents.show', [
            'document' => $document,
        ]);
    }

    public function chunks(Document $document): View
    {
        $chunks = $document->activeChunks()
            ->orderBy('chunk_index')
            ->paginate(20);

        return view('admin.documents.chunks', [
            'document' => $document,
            'chunks' => $chunks,
        ]);
    }

    public function versions(Document $document): View
    {
        $versions = $document->versions()
            ->withCount('chunks')
            ->latest()
            ->paginate(20);

        return view('admin.documents.versions', [
            'document' => $document,
            'versions' => $versions,
        ]);
    }

    public function reindex(Document $document): RedirectResponse
    {
        ReindexDocumentJob::dispatch($document->id);

        return back()->with('status', 'Реиндексация запущена.');
    }

    public function destroy(Document $document): RedirectResponse
    {
        $document->delete();

        return redirect()
            ->route('admin.documents.index')
            ->with('status', 'Документ удалён.');
    }
}
```

---

## 7. ReindexDocumentJob

Если job ещё нет, создать:

```bash
php artisan make:job ReindexDocumentJob
```

Пример:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Documents\Services\DocumentProcessingService;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ReindexDocumentJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $documentId,
    ) {
    }

    public function handle(DocumentProcessingService $processingService): void
    {
        $document = Document::query()->findOrFail($this->documentId);

        $processingService->process($document);
    }
}
```

Если уже есть `ProcessDocumentJob`, можно использовать его же:

```php
ProcessDocumentJob::dispatch($document->id);
```

Но отдельный `ReindexDocumentJob` удобнее для логики:

```text
- пометить старые чанки inactive;
- создать новую версию;
- сохранить новую статистику.
```

---

## 8. Layout админки

Создать:

```text
resources/views/layouts/admin.blade.php
```

```blade
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>@yield('title', 'RAG Admin')</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            font-family: system-ui, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            margin: 0;
        }

        .layout {
            display: grid;
            grid-template-columns: 240px 1fr;
            min-height: 100vh;
        }

        .sidebar {
            background: #0f172a;
            color: white;
            padding: 24px;
        }

        .sidebar a {
            display: block;
            color: #cbd5e1;
            text-decoration: none;
            margin-bottom: 12px;
        }

        .sidebar a:hover {
            color: white;
        }

        .content {
            padding: 32px;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 4px rgba(15, 23, 42, 0.08);
            margin-bottom: 20px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            background: white;
        }

        .table th,
        .table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
            vertical-align: top;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            background: #e2e8f0;
        }

        .badge-success {
            background: #dcfce7;
            color: #166534;
        }

        .badge-warning {
            background: #fef9c3;
            color: #854d0e;
        }

        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .btn {
            display: inline-block;
            padding: 8px 12px;
            border-radius: 8px;
            background: #2563eb;
            color: white;
            border: 0;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-danger {
            background: #dc2626;
        }

        .btn-secondary {
            background: #475569;
        }

        pre {
            white-space: pre-wrap;
            word-break: break-word;
            background: #0f172a;
            color: #e2e8f0;
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
<div class="layout">
    <aside class="sidebar">
        <h2>RAG Admin</h2>

        <a href="{{ route('admin.documents.index') }}">Документы</a>
        <a href="{{ route('admin.documents.create') }}">Загрузить документ</a>
        <a href="{{ route('admin.rag-queries.index') }}">RAG-запросы</a>
    </aside>

    <main class="content">
        @if (session('status'))
            <div class="card">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </main>
</div>
</body>
</html>
```

---

## 9. Страница списка документов

Создать:

```text
resources/views/admin/documents/index.blade.php
```

```blade
@extends('layouts.admin')

@section('title', 'Документы')

@section('content')
    <div class="card">
        <h1>Документы</h1>

        <a class="btn" href="{{ route('admin.documents.create') }}">
            Загрузить документ
        </a>
    </div>

    <div class="card">
        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Название</th>
                <th>Файл</th>
                <th>Статус</th>
                <th>Версий</th>
                <th>Чанков</th>
                <th>Активных чанков</th>
                <th>Дата</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($documents as $document)
                <tr>
                    <td>{{ $document->id }}</td>
                    <td>
                        <a href="{{ route('admin.documents.show', $document) }}">
                            {{ $document->title }}
                        </a>
                    </td>
                    <td>
                        {{ $document->original_filename }}
                        <br>
                        <small>.{{ $document->extension }}</small>
                    </td>
                    <td>
                        @php
                            $class = match ($document->status) {
                                'indexed' => 'badge-success',
                                'failed' => 'badge-danger',
                                'processing' => 'badge-warning',
                                default => '',
                            };
                        @endphp

                        <span class="badge {{ $class }}">
                            {{ $document->status }}
                        </span>

                        @if ($document->error_message)
                            <br>
                            <small style="color: #dc2626;">
                                {{ Str::limit($document->error_message, 80) }}
                            </small>
                        @endif
                    </td>
                    <td>{{ $document->versions_count }}</td>
                    <td>{{ $document->chunks_count }}</td>
                    <td>{{ $document->active_chunks_count }}</td>
                    <td>{{ $document->created_at?->format('d.m.Y H:i') }}</td>
                    <td>
                        <a class="btn btn-secondary" href="{{ route('admin.documents.show', $document) }}">
                            Открыть
                        </a>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        {{ $documents->links() }}
    </div>
@endsection
```

---

## 10. Страница загрузки документа

Создать:

```text
resources/views/admin/documents/create.blade.php
```

```blade
@extends('layouts.admin')

@section('title', 'Загрузка документа')

@section('content')
    <div class="card">
        <h1>Загрузить документ</h1>

        <form method="POST"
              action="{{ route('admin.documents.store') }}"
              enctype="multipart/form-data">
            @csrf

            <div>
                <label for="file">Файл .md или .docx</label>
                <br>
                <input id="file" type="file" name="file" required>
                @error('file')
                    <div style="color: #dc2626;">{{ $message }}</div>
                @enderror
            </div>

            <br>

            <button class="btn" type="submit">
                Загрузить и индексировать
            </button>
        </form>
    </div>
@endsection
```

---

## 11. Страница карточки документа

Создать:

```text
resources/views/admin/documents/show.blade.php
```

```blade
@extends('layouts.admin')

@section('title', 'Документ #' . $document->id)

@section('content')
    <div class="card">
        <h1>{{ $document->title }}</h1>

        <p>
            <strong>Файл:</strong> {{ $document->original_filename }}<br>
            <strong>Расширение:</strong> .{{ $document->extension }}<br>
            <strong>Статус:</strong> {{ $document->status }}<br>
            <strong>Путь:</strong> {{ $document->source_path }}<br>
            <strong>Content hash:</strong> {{ $document->content_hash ?? '—' }}<br>
            <strong>Создан:</strong> {{ $document->created_at?->format('d.m.Y H:i') }}<br>
            <strong>Обновлен:</strong> {{ $document->updated_at?->format('d.m.Y H:i') }}
        </p>

        @if ($document->error_message)
            <div class="card" style="border: 1px solid #dc2626;">
                <strong>Ошибка:</strong>
                <pre>{{ $document->error_message }}</pre>
            </div>
        @endif

        <p>
            <strong>Версий:</strong> {{ $document->versions_count }}<br>
            <strong>Всего чанков:</strong> {{ $document->chunks_count }}<br>
            <strong>Активных чанков:</strong> {{ $document->active_chunks_count }}
        </p>

        <a class="btn btn-secondary" href="{{ route('admin.documents.chunks', $document) }}">
            Смотреть чанки
        </a>

        <a class="btn btn-secondary" href="{{ route('admin.documents.versions', $document) }}">
            Смотреть версии
        </a>

        <form method="POST"
              action="{{ route('admin.documents.reindex', $document) }}"
              style="display: inline;">
            @csrf
            <button class="btn" type="submit">
                Реиндексировать
            </button>
        </form>

        <form method="POST"
              action="{{ route('admin.documents.destroy', $document) }}"
              style="display: inline;"
              onsubmit="return confirm('Удалить документ?')">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger" type="submit">
                Удалить
            </button>
        </form>
    </div>

    <div class="card">
        <h2>Последние версии</h2>

        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Hash</th>
                <th>Статус</th>
                <th>Дата</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($document->versions as $version)
                <tr>
                    <td>{{ $version->id }}</td>
                    <td>{{ $version->version_hash }}</td>
                    <td>{{ $version->status }}</td>
                    <td>{{ $version->created_at?->format('d.m.Y H:i') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
```

---

## 12. Страница чанков документа

Создать:

```text
resources/views/admin/documents/chunks.blade.php
```

```blade
@extends('layouts.admin')

@section('title', 'Чанки документа #' . $document->id)

@section('content')
    <div class="card">
        <h1>Чанки: {{ $document->title }}</h1>

        <a class="btn btn-secondary" href="{{ route('admin.documents.show', $document) }}">
            Назад к документу
        </a>
    </div>

    @foreach ($chunks as $chunk)
        <div class="card">
            <h3>
                Chunk #{{ $chunk->chunk_index }}
                @if ($chunk->heading)
                    — {{ $chunk->heading }}
                @endif
            </h3>

            <p>
                <strong>ID:</strong> {{ $chunk->id }}<br>
                <strong>Version ID:</strong> {{ $chunk->document_version_id }}<br>
                <strong>Section path:</strong> {{ $chunk->section_path ?? '—' }}<br>
                <strong>Page:</strong> {{ $chunk->page_number ?? '—' }}<br>
                <strong>Chars:</strong> {{ $chunk->char_count ?? '—' }}<br>
                <strong>Tokens estimate:</strong> {{ $chunk->token_estimate ?? '—' }}<br>
                <strong>Hash:</strong> {{ $chunk->content_hash }}<br>
                <strong>Active:</strong> {{ $chunk->is_active ? 'yes' : 'no' }}
            </p>

            <details open>
                <summary>Content</summary>
                <pre>{{ $chunk->content }}</pre>
            </details>

            <details>
                <summary>Metadata</summary>
                <pre>{{ json_encode($chunk->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            </details>
        </div>
    @endforeach

    {{ $chunks->links() }}
@endsection
```

---

## 13. Страница версий документа

Создать:

```text
resources/views/admin/documents/versions.blade.php
```

```blade
@extends('layouts.admin')

@section('title', 'Версии документа #' . $document->id)

@section('content')
    <div class="card">
        <h1>Версии: {{ $document->title }}</h1>

        <a class="btn btn-secondary" href="{{ route('admin.documents.show', $document) }}">
            Назад к документу
        </a>
    </div>

    <div class="card">
        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Hash</th>
                <th>Статус</th>
                <th>Чанков</th>
                <th>Metadata</th>
                <th>Дата</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($versions as $version)
                <tr>
                    <td>{{ $version->id }}</td>
                    <td>{{ $version->version_hash }}</td>
                    <td>{{ $version->status }}</td>
                    <td>{{ $version->chunks_count }}</td>
                    <td>
                        <details>
                            <summary>Показать</summary>
                            <pre>{{ json_encode($version->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </details>
                    </td>
                    <td>{{ $version->created_at?->format('d.m.Y H:i') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        {{ $versions->links() }}
    </div>
@endsection
```

---

## 14. AdminRagQueryController

Создать:

```text
app/Http/Controllers/Admin/AdminRagQueryController.php
```

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RagQuery;
use Illuminate\View\View;

final class AdminRagQueryController extends Controller
{
    public function index(): View
    {
        $queries = RagQuery::query()
            ->withCount('chunks')
            ->latest()
            ->paginate(20);

        return view('admin.rag-queries.index', [
            'queries' => $queries,
        ]);
    }

    public function show(RagQuery $ragQuery): View
    {
        $ragQuery->load([
            'chunks.chunk.document',
        ]);

        return view('admin.rag-queries.show', [
            'ragQuery' => $ragQuery,
        ]);
    }
}
```

---

## 15. Страница списка RAG-запросов

Создать:

```text
resources/views/admin/rag-queries/index.blade.php
```

```blade
@extends('layouts.admin')

@section('title', 'RAG-запросы')

@section('content')
    <div class="card">
        <h1>RAG-запросы</h1>
    </div>

    <div class="card">
        <table class="table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Вопрос</th>
                <th>Модель</th>
                <th>Embedding</th>
                <th>Top K</th>
                <th>Чанков</th>
                <th>Latency</th>
                <th>Cost</th>
                <th>Дата</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($queries as $query)
                <tr>
                    <td>{{ $query->id }}</td>
                    <td>
                        <a href="{{ route('admin.rag-queries.show', $query) }}">
                            {{ Str::limit($query->question, 100) }}
                        </a>
                    </td>
                    <td>{{ $query->llm_model }}</td>
                    <td>{{ $query->embedding_model }}</td>
                    <td>{{ $query->top_k }}</td>
                    <td>{{ $query->chunks_count }}</td>
                    <td>{{ $query->total_ms ?? '—' }} ms</td>
                    <td>{{ $query->estimated_cost_usd ?? '—' }}</td>
                    <td>{{ $query->created_at?->format('d.m.Y H:i') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        {{ $queries->links() }}
    </div>
@endsection
```

---

## 16. Страница RAG-запроса

Создать:

```text
resources/views/admin/rag-queries/show.blade.php
```

```blade
@extends('layouts.admin')

@section('title', 'RAG-запрос #' . $ragQuery->id)

@section('content')
    <div class="card">
        <h1>RAG-запрос #{{ $ragQuery->id }}</h1>

        <p>
            <strong>LLM:</strong> {{ $ragQuery->llm_provider }} / {{ $ragQuery->llm_model }}<br>
            <strong>Embedding:</strong> {{ $ragQuery->embedding_model }}<br>
            <strong>Top K:</strong> {{ $ragQuery->top_k }}<br>
            <strong>Total latency:</strong> {{ $ragQuery->total_ms ?? '—' }} ms<br>
            <strong>Prompt tokens:</strong> {{ $ragQuery->prompt_tokens ?? '—' }}<br>
            <strong>Completion tokens:</strong> {{ $ragQuery->completion_tokens ?? '—' }}<br>
            <strong>Total tokens:</strong> {{ $ragQuery->total_tokens ?? '—' }}<br>
            <strong>Estimated cost:</strong> {{ $ragQuery->estimated_cost_usd ?? '—' }}
        </p>
    </div>

    <div class="card">
        <h2>Вопрос</h2>
        <pre>{{ $ragQuery->question }}</pre>
    </div>

    <div class="card">
        <h2>Ответ</h2>
        <pre>{{ $ragQuery->answer }}</pre>
    </div>

    <div class="card">
        <h2>Metadata</h2>
        <pre>{{ json_encode($ragQuery->metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
    </div>

    <div class="card">
        <h2>Использованные чанки</h2>

        <table class="table">
            <thead>
            <tr>
                <th>Rank</th>
                <th>Distance</th>
                <th>Score</th>
                <th>Документ</th>
                <th>Chunk</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($ragQuery->chunks as $queryChunk)
                <tr>
                    <td>{{ $queryChunk->rank }}</td>
                    <td>{{ $queryChunk->distance }}</td>
                    <td>{{ $queryChunk->score }}</td>
                    <td>
                        @if ($queryChunk->chunk?->document)
                            <a href="{{ route('admin.documents.show', $queryChunk->chunk->document) }}">
                                {{ $queryChunk->chunk->document->title }}
                            </a>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        <details>
                            <summary>
                                Chunk #{{ $queryChunk->chunk?->chunk_index }}
                                {{ $queryChunk->chunk?->heading ? '— '.$queryChunk->chunk->heading : '' }}
                            </summary>
                            <pre>{{ $queryChunk->chunk?->content }}</pre>
                        </details>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
```

---

## 17. Что нужно в моделях

### Document

Уже желательно иметь:

```php
public function versions(): HasMany
{
    return $this->hasMany(DocumentVersion::class);
}

public function chunks(): HasMany
{
    return $this->hasMany(DocumentChunk::class);
}

public function activeChunks(): HasMany
{
    return $this->chunks()->where('is_active', true);
}
```

---

### DocumentVersion

```php
public function chunks(): HasMany
{
    return $this->hasMany(DocumentChunk::class);
}
```

---

### RagQuery

```php
public function chunks(): HasMany
{
    return $this->hasMany(RagQueryChunk::class);
}
```

---

### RagQueryChunk

```php
public function chunk(): BelongsTo
{
    return $this->belongsTo(DocumentChunk::class, 'document_chunk_id');
}
```

---

## 18. Важный момент про удаление

Есть два варианта.

### Hard delete

```php
$document->delete();
```

Если в БД стоят foreign keys with cascade, удалятся версии и чанки.

Плюс:

```text
просто
```

Минус:

```text
история RAG-запросов может ссылаться на удалённые чанки
```

---

### Soft delete

Рекомендуемый вариант:

```bash
php artisan make:migration add_deleted_at_to_documents_table
```

```php
Schema::table('documents', function (Blueprint $table) {
    $table->softDeletes();
});
```

В модели:

```php
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;
}
```

Плюс:

```text
можно восстановить документ
история RAG-запросов не ломается
```

Для pet-проекта можно начать с hard delete, но лучше soft delete.

---

## 19. Что показывать в UI для debug RAG

Самые полезные поля:

### Для документа

```text
status
error_message
content_hash
versions_count
active_chunks_count
```

### Для чанка

```text
chunk_index
heading
section_path
char_count
token_estimate
content
metadata
```

### Для RAG query

```text
question
answer
used chunks
distance
score
rank
latency
tokens
cost
```

---

## 20. Порядок реализации

### Шаг 1

Добавить layout:

```text
resources/views/layouts/admin.blade.php
```

### Шаг 2

Добавить routes.

### Шаг 3

Добавить `AdminDocumentController`.

### Шаг 4

Добавить views документов:

```text
index
create
show
chunks
versions
```

### Шаг 5

Добавить upload документа.

### Шаг 6

Добавить reindex button.

### Шаг 7

Добавить delete button.

### Шаг 8

Добавить `AdminRagQueryController`.

### Шаг 9

Добавить views RAG-запросов.

---

## 21. Acceptance Criteria

```text
1. /admin/documents показывает список документов.
2. Можно загрузить .md или .docx.
3. После загрузки запускается индексация.
4. В карточке документа виден статус.
5. Можно открыть список чанков.
6. Можно посмотреть content и metadata чанка.
7. Можно посмотреть версии документа.
8. Можно запустить реиндексацию.
9. Можно удалить документ.
10. Можно посмотреть историю RAG-запросов.
11. Можно посмотреть какие чанки использовались при ответе.
```

---

## 22. Итог

Blade-админка — важная часть RAG-проекта.

Без неё сложно понять:

```text
почему документ плохо ищется;
почему LLM отвечает неправильно;
какие чанки реально попадают в prompt;
насколько хорошо работает chunking;
насколько полезен reranking.
```

Поэтому даже простая админка на Blade сильно ускорит разработку и отладку RAG.
