<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminDocumentController;
use App\Http\Controllers\Admin\AdminRagQueryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('admin.dashboard');
});

Route::prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('/', AdminDashboardController::class)->name('dashboard');

        Route::get('/documents', [AdminDocumentController::class, 'index'])->name('documents.index');
        Route::get('/documents/create', [AdminDocumentController::class, 'create'])->name('documents.create');
        Route::post('/documents', [AdminDocumentController::class, 'store'])->name('documents.store');
        Route::get('/documents/{document}', [AdminDocumentController::class, 'show'])
            ->whereNumber('document')
            ->name('documents.show');
        Route::get('/documents/{document}/chunks', [AdminDocumentController::class, 'chunks'])
            ->whereNumber('document')
            ->name('documents.chunks');
        Route::get('/documents/{document}/versions', [AdminDocumentController::class, 'versions'])
            ->whereNumber('document')
            ->name('documents.versions');
        Route::post('/documents/{document}/reindex', [AdminDocumentController::class, 'reindex'])
            ->whereNumber('document')
            ->name('documents.reindex');
        Route::delete('/documents/{document}', [AdminDocumentController::class, 'destroy'])
            ->whereNumber('document')
            ->name('documents.destroy');

        Route::get('/rag-queries', [AdminRagQueryController::class, 'index'])->name('rag-queries.index');
        Route::get('/rag-queries/{ragQuery}', [AdminRagQueryController::class, 'show'])
            ->whereNumber('ragQuery')
            ->name('rag-queries.show');
    });
