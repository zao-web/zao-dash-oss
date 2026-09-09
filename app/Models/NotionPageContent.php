<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotionPageContent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'content_blocks' => 'array',
        'synced_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(NotionPage::class, 'notion_page_id');
    }
}
