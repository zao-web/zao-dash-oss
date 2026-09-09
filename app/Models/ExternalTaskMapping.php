<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalTaskMapping extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'external_data' => 'array',
        'external_updated_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    // Sync statuses
    public const STATUS_SYNCED = 'synced';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFLICT = 'conflict';

    public const STATUS_ERROR = 'error';

    // Sync directions
    public const DIRECTION_INBOUND = 'inbound';     // External → Internal

    public const DIRECTION_OUTBOUND = 'outbound';   // Internal → External

    public const DIRECTION_BIDIRECTIONAL = 'bidirectional';

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ExternalTaskSource::class, 'external_task_source_id');
    }

    /**
     * Check if external task has been updated since last sync
     */
    public function hasExternalChanges(): bool
    {
        if (! $this->external_updated_at || ! $this->last_synced_at) {
            return true;
        }

        return $this->external_updated_at->gt($this->last_synced_at);
    }

    /**
     * Check if internal task has been updated since last sync
     */
    public function hasInternalChanges(): bool
    {
        if (! $this->last_synced_at) {
            return true;
        }

        return $this->task->updated_at->gt($this->last_synced_at);
    }

    /**
     * Detect if there's a conflict (both sides changed)
     */
    public function hasConflict(): bool
    {
        return $this->hasExternalChanges() && $this->hasInternalChanges();
    }

    public function markSynced(): void
    {
        $this->update([
            'sync_status' => self::STATUS_SYNCED,
            'last_synced_at' => now(),
        ]);
    }

    public function markConflict(): void
    {
        $this->update(['sync_status' => self::STATUS_CONFLICT]);
    }

    public function markError(): void
    {
        $this->update(['sync_status' => self::STATUS_ERROR]);
    }

    public function scopeSynced($query)
    {
        return $query->where('sync_status', self::STATUS_SYNCED);
    }

    public function scopeConflicts($query)
    {
        return $query->where('sync_status', self::STATUS_CONFLICT);
    }

    public function scopeErrors($query)
    {
        return $query->where('sync_status', self::STATUS_ERROR);
    }
}
