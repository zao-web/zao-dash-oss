<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotionDatabaseItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
        'due_date' => 'date',
        'synced_at' => 'datetime',
    ];

    public function database(): BelongsTo
    {
        return $this->belongsTo(NotionPage::class, 'notion_page_id');
    }

    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeOverdue($query)
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->where('status', '!=', 'Done');
    }

    public function scopeUpcoming($query, int $days = 7)
    {
        return $query->whereNotNull('due_date')
            ->whereBetween('due_date', [now(), now()->addDays($days)]);
    }
}
