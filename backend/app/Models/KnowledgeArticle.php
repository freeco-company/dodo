<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class KnowledgeArticle extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'slug',
        'title',
        'category',
        'tags',
        'audience',
        'summary',
        'body',
        'dodo_voice_body',
        'reading_time_seconds',
        'source_image',
        'source_attribution',
        'published_at',
        'view_count',
        'saved_count',
    ];

    protected $casts = [
        'tags' => 'array',
        'audience' => 'array',
        'published_at' => 'datetime',
        'reading_time_seconds' => 'integer',
        'view_count' => 'integer',
        'saved_count' => 'integer',
    ];

    public function scopePublished($query)
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function scopeForAudience($query, string $audience)
    {
        return $query->whereJsonContains('audience', $audience);
    }
}
