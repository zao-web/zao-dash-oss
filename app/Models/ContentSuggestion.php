<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentSuggestion extends Model
{
    protected $guarded = [];

    protected $casts = [
        'source_data' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(WordPressSite::class, 'wordpress_site_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function approve(): void
    {
        $this->update(['status' => 'approved']);
    }

    public function dismiss(): void
    {
        $this->update(['status' => 'dismissed']);
    }

    public function markPublished(int $wpPostId): void
    {
        $this->update([
            'status' => 'published',
            'wp_post_id' => $wpPostId,
        ]);
    }
}
