<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RagQuery extends Model
{
    protected $fillable = [
        'user_id',
        'question',
        'answer',
        'llm_provider',
        'llm_model',
        'embedding_model',
        'top_k',
        'embedding_ms',
        'vector_search_ms',
        'keyword_search_ms',
        'hybrid_merge_ms',
        'rerank_ms',
        'prompt_build_ms',
        'llm_ms',
        'total_ms',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'estimated_cost_usd',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'estimated_cost_usd' => 'decimal:8',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(RagQueryChunk::class);
    }
}
