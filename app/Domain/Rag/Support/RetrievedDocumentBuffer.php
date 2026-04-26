<?php

declare(strict_types=1);

namespace App\Domain\Rag\Support;

use NeuronAI\RAG\Document;

final class RetrievedDocumentBuffer
{
    /**
     * @var Document[]
     */
    private array $documents = [];

    /**
     * @param Document[] $documents
     */
    public function replace(array $documents): void
    {
        $this->documents = $documents;
    }

    public function clear(): void
    {
        $this->documents = [];
    }

    /**
     * @return Document[]
     */
    public function all(): array
    {
        return $this->documents;
    }
}
