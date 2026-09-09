<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PmSyncLog extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'details' => 'array',
    ];

    // Operations
    public const OP_IMPORT = 'import';

    public const OP_UPDATE = 'update';

    public const OP_SYNC_BACK = 'sync_back';

    public const OP_FULL_SYNC = 'full_sync';

    public const OP_ERROR = 'error';

    // Statuses
    public const STATUS_SUCCESS = 'success';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PmConnection::class, 'pm_connection_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ExternalTaskSource::class, 'external_task_source_id');
    }

    /**
     * Start a new sync log
     */
    public static function start(
        PmConnection $connection,
        string $operation,
        ?ExternalTaskSource $source = null
    ): self {
        return self::create([
            'pm_connection_id' => $connection->id,
            'external_task_source_id' => $source?->id,
            'operation' => $operation,
            'status' => self::STATUS_SUCCESS, // Will be updated if errors
        ]);
    }

    /**
     * Record task was processed
     */
    public function taskProcessed(): void
    {
        $this->increment('tasks_processed');
    }

    public function taskCreated(): void
    {
        $this->increment('tasks_created');
        $this->taskProcessed();
    }

    public function taskUpdated(): void
    {
        $this->increment('tasks_updated');
        $this->taskProcessed();
    }

    public function taskSkipped(): void
    {
        $this->increment('tasks_skipped');
        $this->taskProcessed();
    }

    public function recordError(string $message): void
    {
        $this->increment('errors_count');

        $details = $this->details ?? [];
        $details['errors'] = $details['errors'] ?? [];
        $details['errors'][] = [
            'message' => $message,
            'at' => now()->toIso8601String(),
        ];
        $this->update(['details' => $details]);
    }

    /**
     * Finalize the sync log with duration
     */
    public function finish(int $durationMs): void
    {
        $status = self::STATUS_SUCCESS;

        if ($this->errors_count > 0 && $this->tasks_processed > 0) {
            $status = self::STATUS_PARTIAL;
        } elseif ($this->errors_count > 0) {
            $status = self::STATUS_FAILED;
        }

        $this->update([
            'status' => $status,
            'duration_ms' => $durationMs,
        ]);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}
