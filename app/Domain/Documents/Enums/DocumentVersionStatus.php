<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

enum DocumentVersionStatus: string
{
    case Processing = 'processing';
    case Indexed = 'indexed';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
