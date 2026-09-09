<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthAlert extends Model
{
    protected $guarded = [];

    protected $casts = [
        'health_score' => 'decimal:1',
        'previous_score' => 'decimal:1',
        'factors' => 'array',
        'escalation_history' => 'array',
        'acknowledged_at' => 'datetime',
        'resolved_at' => 'datetime',
        'next_escalation_at' => 'datetime',
    ];

    const STATUS_OPEN = 'open';

    const STATUS_ACKNOWLEDGED = 'acknowledged';

    const STATUS_ESCALATED = 'escalated';

    const STATUS_RESOLVED = 'resolved';

    const SEVERITY_LOW = 'low';

    const SEVERITY_MEDIUM = 'medium';

    const SEVERITY_HIGH = 'high';

    const SEVERITY_CRITICAL = 'critical';

    const TYPE_HEALTH_CRITICAL = 'health_critical';

    const TYPE_HEALTH_WARNING = 'health_warning';

    const TYPE_SENTIMENT_NEGATIVE = 'sentiment_negative';

    const TYPE_CHURN_RISK = 'churn_risk';

    const TYPE_DECLINING_TREND = 'declining_trend';

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function acknowledge(int $userId): void
    {
        $this->update([
            'status' => self::STATUS_ACKNOWLEDGED,
            'acknowledged_at' => now(),
            'acknowledged_by' => $userId,
        ]);
    }

    public function resolve(int $userId, ?string $note = null): void
    {
        $this->update([
            'status' => self::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by' => $userId,
            'resolution_note' => $note,
        ]);
    }

    public function escalate(): void
    {
        $history = $this->escalation_history ?? [];
        $history[] = [
            'level' => $this->escalation_level,
            'escalated_at' => now()->toIso8601String(),
            'reason' => 'Auto-escalation due to no acknowledgment',
        ];

        $this->update([
            'status' => self::STATUS_ESCALATED,
            'escalation_level' => $this->escalation_level + 1,
            'escalation_history' => $history,
            'next_escalation_at' => $this->calculateNextEscalationTime(),
        ]);
    }

    protected function calculateNextEscalationTime(): ?\DateTime
    {
        // Escalation intervals get shorter as severity increases
        $intervals = [
            self::SEVERITY_LOW => [24, 48, 72], // hours for each level
            self::SEVERITY_MEDIUM => [12, 24, 48],
            self::SEVERITY_HIGH => [4, 8, 24],
            self::SEVERITY_CRITICAL => [1, 2, 4],
        ];

        $severityIntervals = $intervals[$this->severity] ?? $intervals[self::SEVERITY_MEDIUM];
        $level = min($this->escalation_level, count($severityIntervals) - 1);

        return now()->addHours($severityIntervals[$level]);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeUnresolved($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_ACKNOWLEDGED, self::STATUS_ESCALATED]);
    }

    public function scopeNeedsEscalation($query)
    {
        return $query->whereIn('status', [self::STATUS_OPEN, self::STATUS_ESCALATED])
            ->where('next_escalation_at', '<=', now())
            ->where('escalation_level', '<', 3); // Max 3 levels
    }

    public function scopeCritical($query)
    {
        return $query->where('severity', self::SEVERITY_CRITICAL);
    }

    public function scopeForClient($query, int $clientId)
    {
        return $query->where('client_id', $clientId);
    }

    public function getSeverityColorAttribute(): string
    {
        return match ($this->severity) {
            self::SEVERITY_LOW => 'emerald',
            self::SEVERITY_MEDIUM => 'amber',
            self::SEVERITY_HIGH => 'orange',
            self::SEVERITY_CRITICAL => 'red',
            default => 'zinc',
        };
    }

    public function getEscalationLevelNameAttribute(): string
    {
        return match ($this->escalation_level) {
            0 => 'Initial',
            1 => 'Manager',
            2 => 'Director',
            3 => 'Executive',
            default => 'Unknown',
        };
    }

    public static function createFromHealthDrop(Client $client, float $oldScore, float $newScore): self
    {
        $severity = match (true) {
            $newScore < 20 => self::SEVERITY_CRITICAL,
            $newScore < 40 => self::SEVERITY_HIGH,
            $newScore < 60 => self::SEVERITY_MEDIUM,
            default => self::SEVERITY_LOW,
        };

        $type = $newScore < 40 ? self::TYPE_HEALTH_CRITICAL : self::TYPE_HEALTH_WARNING;

        $alert = self::create([
            'client_id' => $client->id,
            'alert_type' => $type,
            'severity' => $severity,
            'health_score' => $newScore,
            'previous_score' => $oldScore,
            'description' => "{$client->name}'s health score dropped from {$oldScore} to {$newScore}",
            'factors' => [
                'score_drop' => $oldScore - $newScore,
                'drop_percentage' => round((($oldScore - $newScore) / $oldScore) * 100, 1),
            ],
            'status' => self::STATUS_OPEN,
            'next_escalation_at' => now()->addHours(
                match ($severity) {
                    self::SEVERITY_CRITICAL => 1,
                    self::SEVERITY_HIGH => 4,
                    self::SEVERITY_MEDIUM => 12,
                    default => 24,
                }
            ),
        ]);

        return $alert;
    }
}
