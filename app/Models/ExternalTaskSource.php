<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalTaskSource extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'field_mappings' => 'array',
        'sync_filters' => 'array',
        'status_mappings' => 'array',
        'auto_import' => 'boolean',
        'sync_back' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    // Source types
    public const TYPE_LIST = 'list';           // ClickUp List

    public const TYPE_FOLDER = 'folder';       // ClickUp Folder

    public const TYPE_SPACE = 'space';         // ClickUp Space

    public const TYPE_DATABASE = 'database';   // Notion Database

    public const TYPE_SLACK = 'slack';                  // Activity feed: Slack messages

    public const TYPE_GITHUB_PR = 'github_pr';          // Activity feed: GitHub pull requests

    public const TYPE_GITHUB_ISSUE = 'github_issue';    // Activity feed: GitHub issues

    public const TYPE_EMAIL = 'email';                  // Activity feed: client-domain emails

    public const TYPE_INTERNAL_TASK = 'internal_task';  // Activity feed: existing internal tasks

    public const TYPE_GOOGLE_SHEET = 'google_sheet';    // Per-client task tracker spreadsheet

    /**
     * @return array<int, string> Source types that originate from the activity feed pipeline.
     */
    public static function activityFeedTypes(): array
    {
        return [
            self::TYPE_SLACK,
            self::TYPE_GITHUB_PR,
            self::TYPE_GITHUB_ISSUE,
            self::TYPE_EMAIL,
            self::TYPE_INTERNAL_TASK,
            self::TYPE_GOOGLE_SHEET,
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PmConnection::class, 'pm_connection_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function taskMappings(): HasMany
    {
        return $this->hasMany(ExternalTaskMapping::class);
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(PmSyncLog::class);
    }

    /**
     * Map external status to internal Task status
     */
    public function mapStatus(string $externalStatus): string
    {
        $mappings = $this->status_mappings ?? [];

        // Direct mapping
        if (isset($mappings[$externalStatus])) {
            return $mappings[$externalStatus];
        }

        // Smart defaults based on common patterns
        $normalized = strtolower($externalStatus);

        if (in_array($normalized, ['done', 'complete', 'completed', 'closed', 'resolved'])) {
            return 'completed';
        }

        if (in_array($normalized, ['in progress', 'in review', 'active', 'doing', 'working'])) {
            return 'in_progress';
        }

        if (in_array($normalized, ['blocked', 'on hold', 'waiting', 'paused'])) {
            return 'blocked';
        }

        return 'pending'; // Default
    }

    /**
     * Map internal status back to external (for sync-back)
     */
    public function mapStatusToExternal(string $internalStatus): ?string
    {
        $mappings = $this->status_mappings ?? [];
        $reversed = array_flip($mappings);

        return $reversed[$internalStatus] ?? null;
    }

    public function scopeAutoImport($query)
    {
        return $query->where('auto_import', true);
    }

    public function scopeSyncBack($query)
    {
        return $query->where('sync_back', true);
    }
}
