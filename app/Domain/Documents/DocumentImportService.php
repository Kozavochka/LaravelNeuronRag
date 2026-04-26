<?php

declare(strict_types=1);

namespace App\Domain\Documents;

use App\Domain\Documents\Enums\DocumentStatus;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentImportService
{
    public function __construct(
        private readonly string $disk = 'local',
        private readonly string $directory = 'rag/documents',
    ) {
    }

    public function importFromUploadedFile(UploadedFile $file, ?string $title = null): Document
    {
        return $this->importFromPath(
            path: $file->getRealPath() ?: $file->path(),
            originalFilename: $file->getClientOriginalName(),
            title: $title,
            mimeType: $file->getClientMimeType(),
        );
    }

    public function importFromPath(
        string $path,
        ?string $originalFilename = null,
        ?string $title = null,
        ?string $mimeType = null,
    ): Document {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Source file [%s] does not exist.', $path));
        }

        $originalFilename ??= basename($path);
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $directory = trim($this->directory, '/').'/'.Str::uuid()->toString();
        $storedFilename = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME)) ?: 'document';
        $storagePath = sprintf('%s/%s.%s', $directory, $storedFilename, $extension);
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Unable to read source file [%s].', $path));
        }

        Storage::disk($this->disk)->put($storagePath, $contents);

        return Document::query()->create([
            'title' => $title ?: pathinfo($originalFilename, PATHINFO_FILENAME),
            'original_filename' => $originalFilename,
            'storage_disk' => $this->disk,
            'storage_path' => $storagePath,
            'mime_type' => $mimeType ?: File::mimeType($path),
            'extension' => $extension,
            'status' => DocumentStatus::Pending,
        ]);
    }
}
