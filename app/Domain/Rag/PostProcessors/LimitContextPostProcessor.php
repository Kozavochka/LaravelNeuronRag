<?php

declare(strict_types=1);

namespace App\Domain\Rag\PostProcessors;

use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\PostProcessorInterface;

final readonly class LimitContextPostProcessor implements PostProcessorInterface
{
    public function __construct(
        private int $maxContextChars,
    ) {
    }

    /**
     * @param Document[] $documents
     * @return Document[]
     */
    public function process(Message $question, array $documents): array
    {
        $selected = [];
        $totalChars = 0;

        foreach ($documents as $document) {
            $contentLength = mb_strlen($document->getContent());

            if ($selected !== [] && ($totalChars + $contentLength) > $this->maxContextChars) {
                break;
            }

            $selected[] = $document;
            $totalChars += $contentLength;

            if ($totalChars >= $this->maxContextChars) {
                break;
            }
        }

        return $selected === [] ? array_slice($documents, 0, 1) : $selected;
    }
}
