<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services;

use App\Models\Document;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class DocumentImportService
{
    public function __construct(
        private readonly DocumentUploadCapabilitiesService $capabilities,
    ) {
    }

    public function import(UploadedFile $file): Document
    {
        $extension = mb_strtolower($file->getClientOriginalExtension());

        if (! $this->capabilities->isExtensionAllowed($extension)) {
            $allowed = $this->capabilities->allowedExtensions();
            throw new InvalidArgumentException('The file field must be a file of type: ' . implode(', ', $allowed) . '.');
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
