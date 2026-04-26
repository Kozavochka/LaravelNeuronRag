<?php

declare(strict_types=1);

namespace App\Domain\Documents\Services\TextExtraction;

use App\Domain\Documents\DTO\ExtractedDocumentText;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;

final class DocxTextExtractor implements DocumentTextExtractor
{
    public function supports(string $extension, ?string $mimeType = null): bool
    {
        return mb_strtolower($extension) === 'docx';
    }

    public function extract(string $path): ExtractedDocumentText
    {
        $phpWord = IOFactory::load($path);
        $blocks = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text = $this->extractElementText($element);

                if ($text !== '') {
                    $blocks[] = $text;
                }
            }
        }

        return new ExtractedDocumentText(
            content: implode("\n\n", $blocks),
            metadata: ['format' => 'docx'],
        );
    }

    private function extractElementText(object $element): string
    {
        if ($element instanceof Text) {
            return trim($element->getText());
        }

        if ($element instanceof TextRun) {
            $parts = [];

            foreach ($element->getElements() as $child) {
                if ($child instanceof Text) {
                    $parts[] = $child->getText();
                }
            }

            return trim(implode('', $parts));
        }

        if ($element instanceof ListItem) {
            return '- ' . trim((string) $element->getTextObject()?->getText());
        }

        if ($element instanceof Table) {
            return $this->extractTable($element);
        }

        return '';
    }

    private function extractTable(Table $table): string
    {
        $rows = [];

        foreach ($table->getRows() as $row) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                $cellParts = [];

                foreach ($cell->getElements() as $cellElement) {
                    $text = $this->extractElementText($cellElement);

                    if ($text !== '') {
                        $cellParts[] = $text;
                    }
                }

                $cells[] = trim(implode(' ', $cellParts));
            }

            if ($cells !== []) {
                $rows[] = '| ' . implode(' | ', $cells) . ' |';
            }
        }

        if (count($rows) > 1) {
            $columnCount = max(1, substr_count($rows[0], '|') - 1);
            $separator = '| ' . implode(' | ', array_fill(0, $columnCount, '---')) . ' |';
            array_splice($rows, 1, 0, [$separator]);
        }

        return implode("\n", $rows);
    }
}
