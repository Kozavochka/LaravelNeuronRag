<?php

declare(strict_types=1);

namespace App\Domain\Documents\Contracts;

use App\Domain\Documents\DTO\MarkitdownConversionResult;
use App\Domain\Documents\DTO\MarkitdownHealthResult;

interface MarkitdownClientInterface
{
    public function health(): MarkitdownHealthResult;

    public function convert(string $absolutePath, string $originalFilename, ?string $mimeType = null): MarkitdownConversionResult;
}
