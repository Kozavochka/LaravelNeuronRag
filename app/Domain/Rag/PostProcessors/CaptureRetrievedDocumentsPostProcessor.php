<?php

declare(strict_types=1);

namespace App\Domain\Rag\PostProcessors;

use App\Domain\Rag\Support\RetrievedDocumentBuffer;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

final readonly class CaptureRetrievedDocumentsPostProcessor implements PostProcessorInterface
{
    public function __construct(
        private RetrievedDocumentBuffer $buffer,
    ) {
    }

    public function process(Message $question, array $documents): array
    {
        $this->buffer->replace($documents);

        return $documents;
    }
}
