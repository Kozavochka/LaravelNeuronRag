<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\TextProcessing;

final class RagTextSanitizer
{
    public function sanitize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[^\P{C}\n\t]+/u', '', $text);
        $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
        $text = (string) preg_replace("/\n{4,}/u", "\n\n\n", $text);
        $text = (string) preg_replace('/Страница\s+\d+\s+из\s+\d+/iu', '', $text);

        return trim($text);
    }
}
