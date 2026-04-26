<?php

declare(strict_types=1);

use App\Http\Controllers\Rag\ChatController;
use App\Http\Controllers\Rag\DocumentController;
use Illuminate\Support\Facades\Route;

Route::prefix('rag')->group(function (): void {
    Route::get('documents', [DocumentController::class, 'index'])->name('rag.documents.index');
    Route::post('documents', [DocumentController::class, 'store'])->name('rag.documents.store');
    Route::get('documents/{document}', [DocumentController::class, 'show'])
        ->whereNumber('document')
        ->name('rag.documents.show');
    Route::post('documents/{document}/reindex', [DocumentController::class, 'reindex'])
        ->whereNumber('document')
        ->name('rag.documents.reindex');

    Route::post('chat', [ChatController::class, 'store'])->name('rag.chat.store');
});
