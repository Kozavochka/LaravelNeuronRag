<?php

declare(strict_types=1);

namespace App\Domain\Documents\TextExtraction;

use App\Domain\Documents\DTO\ExtractedDocumentText;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\ListItemRun;
use PhpOffice\PhpWord\Element\PreserveText;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use RuntimeException;

class DocxTextExtractor implements TextExtractorInterface
{
    public function supports(string $extension, ?string $mimeType = null): bool
    {
        return strtolower($extension) === 'docx'
            || $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    }

    public function extract(string $path): ExtractedDocumentText
    {
        if (! is_file($path)) {
            throw new RuntimeException(sprintf('DOCX file [%s] does not exist.', $path));
        }

        $phpWord = IOFactory::load($path);
        $lines = [];
        $headings = [];

        foreach ($phpWord->getSections() as $section) {
            $this->extractFromContainer($section, $lines, $headings);
        }

        $content = trim(implode("\n\n", array_filter($lines, static fn (?string $line): bool => $line !== null && $line !== '')));

        if ($headings === [] && isset($lines[0])) {
            $headings[] = ltrim($lines[0], "# \t\n\r\0\x0B");
        }

        return new ExtractedDocumentText(
            content: $content,
            metadata: [
                'source_format' => 'docx',
                'heading_count' => count($headings),
            ],
            headings: $headings,
            title: $headings[0] ?? null,
        );
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $headings
     */
    private function extractFromContainer(AbstractContainer $container, array &$lines, array &$headings): void
    {
        foreach ($container->getElements() as $element) {
            if ($element instanceof Title) {
                $text = trim($element->getText() ?? '');

                if ($text !== '') {
                    $headings[] = $text;
                    $lines[] = '# '.$text;
                }

                continue;
            }

            if ($element instanceof Text) {
                $text = trim($element->getText() ?? '');

                if ($text !== '') {
                    $lines[] = $text;
                }

                continue;
            }

            if ($element instanceof PreserveText) {
                $text = trim($element->getText() ?? '');

                if ($text !== '') {
                    $lines[] = $text;
                }

                continue;
            }

            if ($element instanceof ListItem) {
                $text = trim($element->getText() ?? '');

                if ($text !== '') {
                    $lines[] = '- '.$text;
                }

                continue;
            }

            if ($element instanceof ListItemRun) {
                $text = trim($this->extractFromTextRun($element));

                if ($text !== '') {
                    $lines[] = '- '.$text;
                }

                continue;
            }

            if ($element instanceof TextRun) {
                $text = trim($this->extractFromTextRun($element));

                if ($text !== '') {
                    $lines[] = $text;
                }

                continue;
            }

            if ($element instanceof Table) {
                foreach ($element->getRows() as $row) {
                    $cells = [];

                    foreach ($row->getCells() as $cell) {
                        $buffer = [];
                        $this->extractFromContainer($cell, $buffer, $headings);
                        $cellText = trim(implode(' ', $buffer));

                        if ($cellText !== '') {
                            $cells[] = $cellText;
                        }
                    }

                    if ($cells !== []) {
                        $lines[] = implode(' | ', $cells);
                    }
                }

                continue;
            }

            if ($element instanceof AbstractContainer) {
                $this->extractFromContainer($element, $lines, $headings);
                continue;
            }

            if (method_exists($element, 'getText')) {
                $text = trim((string) $element->getText());

                if ($text !== '') {
                    $lines[] = $text;
                }
            }
        }
    }

    private function extractFromTextRun(TextRun|ListItemRun $run): string
    {
        $parts = [];

        foreach ($run->getElements() as $element) {
            if (method_exists($element, 'getText')) {
                $text = trim((string) $element->getText());

                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return implode(' ', $parts);
    }
}
