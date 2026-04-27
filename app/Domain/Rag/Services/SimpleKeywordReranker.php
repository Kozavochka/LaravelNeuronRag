<?php

declare(strict_types=1);

namespace App\Domain\Rag\Services;

use App\Domain\Rag\Contracts\RerankerInterface;
use NeuronAI\RAG\Document;

use function array_slice;
use function array_values;
use function explode;
use function in_array;
use function is_string;
use function mb_strlen;
use function mb_strtolower;
use function preg_replace;
use function str_contains;
use function trim;
use function usort;

final class SimpleKeywordReranker implements RerankerInterface
{
    /**
     * @param array<int, Document> $chunks
     * @return array<int, Document>
     */
    public function rerank(string $query, array $chunks, int $limit): array
    {
        if ($chunks === []) {
            return [];
        }

        $tokens = $this->extractQueryTokens($query);

        foreach ($chunks as $index => $chunk) {
            $baseScore = $this->baseScore($chunk);
            $content = mb_strtolower($chunk->getContent());
            $heading = mb_strtolower((string) ($chunk->metadata['heading'] ?? ''));
            $sectionPath = mb_strtolower((string) ($chunk->metadata['section_path'] ?? ''));

            $score = $baseScore;

            foreach ($tokens as $token) {
                if (str_contains($content, $token)) {
                    $score += 0.03;
                }

                if ($heading !== '' && str_contains($heading, $token)) {
                    $score += 0.05;
                }

                if ($sectionPath !== '' && str_contains($sectionPath, $token)) {
                    $score += 0.04;
                }
            }

            $chunk->metadata['rerank_score'] = $score;
            $chunk->metadata['vector_rank'] = (int) ($chunk->metadata['rank'] ?? $index + 1);
        }

        usort($chunks, static function (Document $left, Document $right): int {
            $leftScore = (float) ($left->metadata['rerank_score'] ?? 0.0);
            $rightScore = (float) ($right->metadata['rerank_score'] ?? 0.0);

            if ($leftScore !== $rightScore) {
                return $rightScore <=> $leftScore;
            }

            $leftRank = (int) ($left->metadata['vector_rank'] ?? PHP_INT_MAX);
            $rightRank = (int) ($right->metadata['vector_rank'] ?? PHP_INT_MAX);

            return $leftRank <=> $rightRank;
        });

        foreach ($chunks as $index => $chunk) {
            $chunk->metadata['rank'] = $index + 1;
        }

        return array_slice(array_values($chunks), 0, max(1, $limit));
    }

    /**
     * @return list<string>
     */
    private function extractQueryTokens(string $query): array
    {
        $normalized = mb_strtolower($query);
        $normalized = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized);
        $parts = explode(' ', $normalized);
        $tokens = [];

        foreach ($parts as $part) {
            $token = trim($part);

            if ($token === '' || mb_strlen($token) < 2 || in_array($token, $tokens, true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }

    private function baseScore(Document $chunk): float
    {
        if (is_string($chunk->metadata['distance'] ?? null)) {
            return 1.0 - (float) $chunk->metadata['distance'];
        }

        if (isset($chunk->metadata['distance'])) {
            return 1.0 - (float) $chunk->metadata['distance'];
        }

        return (float) $chunk->getScore();
    }
}
