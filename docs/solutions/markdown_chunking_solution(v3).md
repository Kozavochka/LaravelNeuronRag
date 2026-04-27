# Проектное решение: Markdown-чанкинг по заголовкам (#, ##, ###) для LaravelNeuronRag

## 1. Цель

Улучшить качество RAG для `.md` документов.

Сейчас документы режутся по длине текста:

```text
1000 символов + overlap
```

Это ломает структуру документа:

- заголовок отдельно;
- смысловой блок разрезан;
- таблица может разделиться;
- раздел FAQ ломается пополам.

Нужно перейти на semantic chunking:

```text
# Заголовки markdown
## Подразделы
### Вложенные блоки
```

---

## 2. Новый пайплайн индексации

```text
.md файл
→ Text Extractor
→ Text Normalizer
→ MarkdownSectionParser
→ MarkdownHeaderChunker
→ MetadataEnricher
→ EmbeddingService
→ PgVectorStore
```

---

## 3. Что нужно реализовать

## Новый namespace

```text
app/Domain/Documents/Markdown/
```

Файлы:

```text
MarkdownSection.php
MarkdownSectionParser.php
MarkdownHeaderChunker.php
```

---

## 4. DTO секции

## app/Domain/Documents/Markdown/MarkdownSection.php

```php
<?php

declare(strict_types=1);

namespace App\Domain\Documents\Markdown;

final readonly class MarkdownSection
{
    public function __construct(
        public string $heading,
        public int $level,
        public array $sectionPath,
        public string $content,
    ) {
    }
}
```

---

## 5. Parser

## MarkdownSectionParser.php

```php
<?php

declare(strict_types=1);

namespace App\Domain\Documents\Markdown;

final class MarkdownSectionParser
{
    public function parse(string $text): array
    {
        $lines = preg_split('/\R/', $text);

        $sections = [];

        $stack = [];
        $currentHeading = 'Root';
        $currentLevel = 0;
        $buffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^(#{1,6})\s+(.*)$/', $line, $m)) {
                if (! empty($buffer)) {
                    $sections[] = new MarkdownSection(
                        heading: $currentHeading,
                        level: $currentLevel,
                        sectionPath: $stack,
                        content: trim(implode("\n", $buffer)),
                    );
                }

                $level = strlen($m[1]);
                $heading = trim($m[2]);

                $stack = array_slice($stack, 0, $level - 1);
                $stack[$level - 1] = $heading;

                $currentHeading = $heading;
                $currentLevel = $level;
                $buffer = [];

                continue;
            }

            $buffer[] = $line;
        }

        if (! empty($buffer)) {
            $sections[] = new MarkdownSection(
                heading: $currentHeading,
                level: $currentLevel,
                sectionPath: $stack,
                content: trim(implode("\n", $buffer)),
            );
        }

        return $sections;
    }
}
```

---

## 6. Chunker

```php
final class MarkdownHeaderChunker
{
    public function chunk(array $sections): array
    {
        $chunks = [];

        foreach ($sections as $section) {
            if (mb_strlen($section->content) < 3000) {
                $chunks[] = [
                    'content' => $section->content,
                    'metadata' => [
                        'heading' => $section->heading,
                        'level' => $section->level,
                        'section_path' => implode(' / ', $section->sectionPath),
                    ],
                ];

                continue;
            }

            $parts = mb_str_split($section->content, 2500);

            foreach ($parts as $index => $part) {
                $chunks[] = [
                    'content' => $part,
                    'metadata' => [
                        'heading' => $section->heading,
                        'part' => $index + 1,
                        'section_path' => implode(' / ', $section->sectionPath),
                    ],
                ];
            }
        }

        return $chunks;
    }
}
```

---

## 7. Где встроить

В `DocumentProcessingService`

Было:

```php
$chunks = $this->chunkingService->split($text);
```

Стало:

```php
if ($document->extension === 'md') {
    $sections = $this->markdownParser->parse($text);
    $chunks = $this->markdownChunker->chunk($sections);
} else {
    $chunks = $this->chunkingService->split($text);
}
```

---

## 8. Что попадёт в embedding

Лучше не только content:

```text
Раздел: RAG / Retrieval / Chunking

Текст чанка...
```

---

## 9. Что хранить в metadata

```json
{
  "chunking_strategy": "markdown_headers",
  "heading": "Chunking",
  "heading_level": 2,
  "section_path": "RAG / Retrieval / Chunking"
}
```

---

## 10. Ожидаемый эффект

```text
+ точнее retrieval
+ лучше source attribution
+ LLM получает структурный контекст
+ меньше бессмысленных чанков
```

---

## 11. Acceptance Criteria

```text
1. md файлы режутся по заголовкам
2. большие секции дополнительно делятся
3. metadata содержит section_path
4. embeddings строятся по новым чанкам
5. docx/pdf работает как раньше
```
