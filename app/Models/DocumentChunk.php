<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentChunk extends Model
{
    protected $fillable = [
        'document_id',
        'document_version_id',
        'chunk_index',
        'content',
        'content_hash',
        'char_count',
        'token_estimate',
        'heading',
        'section_path',
        'page_number',
        'metadata',
        'is_active',
        'embedding',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'document_version_id');
    }

    public function ragQueryChunks(): HasMany
    {
        return $this->hasMany(RagQueryChunk::class);
    }
}
