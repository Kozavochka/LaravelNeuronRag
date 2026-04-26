<?php

declare(strict_types=1);

namespace App\Domain\Documents\TextProcessing;

class RagTextSanitizer
{
    public function sanitize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        $lines = array_map(
            static fn (string $line): string => trim($line),
            explode("\n", $text),
        );

        return trim(implode("\n", $lines));
    }
}
