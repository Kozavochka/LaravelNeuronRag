<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RagQueryChunk extends Model
{
    protected $fillable = [
        'rag_query_id',
        'document_chunk_id',
        'distance',
        'score',
        'rerank_score',
        'rank',
    ];

    public function query(): BelongsTo
    {
        return $this->belongsTo(RagQuery::class);
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(DocumentChunk::class);
    }
}
