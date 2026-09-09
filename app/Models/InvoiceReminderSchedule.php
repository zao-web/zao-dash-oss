<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceReminderSchedule extends Model
{
    public const SCOPE_GLOBAL = 'global';

    public const SCOPE_CLIENT = 'client';

    /** Built-in fallback if no global row exists. */
    public const DEFAULT_SCHEDULE = [
        ['offset_days' => -3, 'enabled' => true],
        ['offset_days' => 0, 'enabled' => true],
        ['offset_days' => 7, 'enabled' => true],
        ['offset_days' => 14, 'enabled' => true],
        ['offset_days' => 30, 'enabled' => true],
    ];

    public const MIN_OFFSET = -90;

    public const MAX_OFFSET = 365;

    protected $fillable = [
        'scope',
        'client_id',
        'enabled',
        'schedule',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'schedule' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Resolve the active schedule for a client, walking client → global → default.
     *
     * @return array{enabled: bool, entries: array<int, array{offset_days: int, enabled: bool}>}
     */
    public static function resolve(?Client $client = null): array
    {
        if ($client) {
            $clientSchedule = static::query()
                ->where('scope', self::SCOPE_CLIENT)
                ->where('client_id', $client->id)
                ->first();

            if ($clientSchedule) {
                return [
                    'enabled' => $clientSchedule->enabled,
                    'entries' => static::normalize($clientSchedule->schedule),
                ];
            }
        }

        $global = static::query()
            ->where('scope', self::SCOPE_GLOBAL)
            ->first();

        if ($global) {
            return [
                'enabled' => $global->enabled,
                'entries' => static::normalize($global->schedule),
            ];
        }

        return [
            'enabled' => true,
            'entries' => self::DEFAULT_SCHEDULE,
        ];
    }

    /**
     * Map a raw offset to a reminder type for templates / labels.
     */
    public static function typeForOffset(int $offsetDays): string
    {
        return match (true) {
            $offsetDays < 0 => InvoiceReminder::TYPE_BEFORE_DUE,
            $offsetDays === 0 => InvoiceReminder::TYPE_ON_DUE,
            default => InvoiceReminder::TYPE_OVERDUE,
        };
    }

    /**
     * Re-schedule pending reminders for every unpaid invoice this schedule affects.
     *
     * Global scope rebuilds for every unpaid invoice whose client has no per-client
     * schedule (since those are the ones that actually resolve to global). Client
     * scope rebuilds only that client's unpaid invoices.
     */
    public function rematerialize(): int
    {
        $query = Invoice::query()
            ->whereNotIn('status', [Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED])
            ->where('reminders_disabled', false);

        if ($this->scope === self::SCOPE_CLIENT) {
            $query->where('client_id', $this->client_id);
        } else {
            // Global: skip clients that have their own override
            $clientsWithOverride = static::query()
                ->where('scope', self::SCOPE_CLIENT)
                ->pluck('client_id')
                ->all();

            if (! empty($clientsWithOverride)) {
                $query->whereNotIn('client_id', $clientsWithOverride);
            }
        }

        $invoices = $query->with('client')->get();

        foreach ($invoices as $invoice) {
            InvoiceReminder::scheduleForInvoice($invoice);
        }

        return $invoices->count();
    }

    /**
     * Coerce/normalize a JSON-stored schedule array into a safe, sorted, deduped list.
     *
     * @param  array<int, array{offset_days: int, enabled?: bool}>|null  $raw
     * @return array<int, array{offset_days: int, enabled: bool}>
     */
    public static function normalize(?array $raw): array
    {
        $entries = collect($raw ?? [])
            ->map(fn ($entry) => [
                'offset_days' => max(self::MIN_OFFSET, min(self::MAX_OFFSET, (int) ($entry['offset_days'] ?? 0))),
                'enabled' => (bool) ($entry['enabled'] ?? true),
            ])
            ->unique('offset_days')
            ->sortBy('offset_days')
            ->values()
            ->all();

        return $entries;
    }
}
