<?php

declare(strict_types=1);

namespace App\Domain\Documents\Contracts;

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Domain\Documents\DTO\PreparedChunk;

interface DocumentVectorStoreInterface
{
    /**
     * @param  PreparedChunk[]  $chunks
     */
    public function replaceDocumentVersion(
        Document $document,
        DocumentVersion $version,
        array $chunks,
    ): void;
}
