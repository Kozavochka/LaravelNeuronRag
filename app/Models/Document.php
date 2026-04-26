<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $fillable = [
        'title',
        'original_filename',
        'mime_type',
        'extension',
        'source_type',
        'source_path',
        'status',
        'content_hash',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    public function activeChunks(): HasMany
    {
        return $this->chunks()->where('is_active', true);
    }

    public function ragQueries(): HasMany
    {
        return $this->hasMany(RagQuery::class, 'user_id');
    }
}
