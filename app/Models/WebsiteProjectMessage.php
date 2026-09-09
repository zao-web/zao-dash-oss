<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteProjectMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'website_project_id',
        'user_id',
        'agent_run_id',
        'role',
        'content',
        'metadata',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_SYSTEM = 'system';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_ERROR = 'error';

    public function project(): BelongsTo
    {
        return $this->belongsTo(WebsiteProject::class, 'website_project_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function agentRun(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class);
    }

    public function isFromUser(): bool
    {
        return $this->role === self::ROLE_USER;
    }

    public function isFromAssistant(): bool
    {
        return $this->role === self::ROLE_ASSISTANT;
    }

    public function isSystemMessage(): bool
    {
        return $this->role === self::ROLE_SYSTEM;
    }

    public function toFrontendArray(): array
    {
        return [
            'id' => (string) $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'timestamp' => $this->created_at->toIso8601String(),
            'status' => $this->status,
            'metadata' => $this->metadata ?? [],
        ];
    }
}
