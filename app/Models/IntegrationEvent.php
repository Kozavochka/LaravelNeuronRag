<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class IntegrationEvent extends Model
{
    protected $fillable = [
        'integration',
        'event_type',
        'status_code',
        'latency_ms',
        'message',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }
}
