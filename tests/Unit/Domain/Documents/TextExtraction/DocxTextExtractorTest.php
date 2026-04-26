<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Documents\TextExtraction;

use App\Domain\Documents\TextExtraction\DocxTextExtractor;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\TestCase;

class DocxTextExtractorTest extends TestCase
{
    public function test_it_extracts_text_lists_tables_and_headings_from_docx(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'docx-test-');

        if ($path === false) {
            self::fail('Unable to allocate temporary file.');
        }

        $docxPath = $path.'.docx';
        @unlink($path);

        $document = new PhpWord();
        $section = $document->addSection();
        $section->addTitle('Heading', 1);
        $section->addText('Body paragraph');
        $section->addListItem('First item');
        $table = $section->addTable();
        $table->addRow();
        $table->addCell()->addText('A1');
        $table->addCell()->addText('B1');

        IOFactory::createWriter($document, 'Word2007')->save($docxPath);

        try {
            $extractor = new DocxTextExtractor();
            $extracted = $extractor->extract($docxPath);

            self::assertSame('Heading', $extracted->title);
            self::assertContains('Heading', $extracted->headings);
            self::assertStringContainsString('Body paragraph', $extracted->content);
            self::assertStringContainsString('- First item', $extracted->content);
            self::assertStringContainsString('A1 | B1', $extracted->content);
        } finally {
            @unlink($docxPath);
        }
    }
}
