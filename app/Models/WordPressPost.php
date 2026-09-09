<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WordPressPost extends Model
{
    protected $table = 'wordpress_posts';

    protected $guarded = [];

    protected $casts = [
        'categories' => 'array',
        'tags' => 'array',
        'published_at' => 'datetime',
        'modified_at' => 'datetime',
        'synced_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(WordPressSite::class, 'wordpress_site_id');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'publish');
    }

    public function scopeDrafts($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
