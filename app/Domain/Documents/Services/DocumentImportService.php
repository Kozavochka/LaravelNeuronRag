<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class DocumentImportService
{
    public function import(UploadedFile $file): Document
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());
        $allowed = config('rag.documents.allowed_extensions', ['md', 'docx']);

        if (! in_array($extension, $allowed, true)) {
            throw new InvalidArgumentException('Only .md and .docx files are supported.');
        }

        $path = $file->store(
            path: config('rag.documents.directory', 'rag/documents'),
            options: ['disk' => config('rag.documents.disk', 'local')],
        );

        return Document::query()->create([
            'title' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'extension' => $extension,
            'source_type' => 'upload',
            'source_path' => $path,
            'status' => 'uploaded',
        ]);
    }
}
