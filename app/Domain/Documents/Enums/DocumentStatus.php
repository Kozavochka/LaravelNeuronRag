<?php

declare(strict_types=1);

namespace App\Domain\Documents\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Indexed = 'indexed';
    case Failed = 'failed';
}
