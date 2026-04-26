<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\TextProcessing;

use App\Domain\Documents\TextProcessing\RagTextSanitizer;
use PHPUnit\Framework\TestCase;

class RagTextSanitizerTest extends TestCase
{
    public function test_it_normalizes_control_characters_and_blank_lines(): void
    {
        $sanitizer = new RagTextSanitizer();

        $input = " Line 1 \r\n\r\n\r\nLine\x07 2\u{00A0}\rLine 3\t \n";

        self::assertSame(
            "Line 1\n\nLine 2\nLine 3",
            $sanitizer->sanitize($input),
        );
    }
}
