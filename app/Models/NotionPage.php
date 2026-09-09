<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class NotionPage extends Model
{
    protected $guarded = [];

    protected $casts = [
        'properties_schema' => 'array',
        'is_database' => 'boolean',
        'archived' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(NotionConnection::class, 'notion_connection_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function content(): HasOne
    {
        return $this->hasOne(NotionPageContent::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(NotionDatabaseItem::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(NotionPage::class, 'parent_id', 'page_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(NotionPage::class, 'parent_id', 'page_id');
    }

    public function scopeDatabases($query)
    {
        return $query->where('is_database', true);
    }

    public function scopePages($query)
    {
        return $query->where('is_database', false);
    }

    public function scopeActive($query)
    {
        return $query->where('archived', false);
    }
}
