<?php

declare(strict_types=1);

namespace App\Domain\Documents\Jobs;

use App\Domain\Documents\Services\Indexing\DocumentIndexingService;
use App\Models\Document;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct(
        public readonly int $documentId,
    ) {
    }

    public function handle(DocumentIndexingService $indexingService): void
    {
        $document = Document::query()->findOrFail($this->documentId);
        $indexingService->index($document);
    }
}
