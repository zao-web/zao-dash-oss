<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceReminder extends Model
{
    public const TYPE_BEFORE_DUE = 'before_due';

    public const TYPE_ON_DUE = 'on_due';

    public const TYPE_OVERDUE = 'overdue';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'invoice_id',
        'type',
        'days_offset',
        'scheduled_at',
        'sent_at',
        'status',
        'error_message',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function markSent(): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $error,
        ]);
    }

    public function cancel(): void
    {
        $this->update(['status' => self::STATUS_CANCELLED]);
    }

    public static function scheduleForInvoice(Invoice $invoice): void
    {
        // Cancel any existing pending reminders
        $invoice->reminders()->where('status', self::STATUS_PENDING)->update([
            'status' => self::STATUS_CANCELLED,
        ]);

        // Don't schedule reminders for paid/cancelled invoices
        if ($invoice->isPaid() || $invoice->status === Invoice::STATUS_CANCELLED) {
            return;
        }

        // Per-invoice opt-out
        if ($invoice->reminders_disabled) {
            return;
        }

        $resolved = InvoiceReminderSchedule::resolve($invoice->client);

        if (! $resolved['enabled']) {
            return;
        }

        foreach ($resolved['entries'] as $entry) {
            if (! ($entry['enabled'] ?? true)) {
                continue;
            }

            $offset = (int) $entry['offset_days'];
            $scheduledAt = $invoice->due_date->copy()->addDays($offset);

            // Only schedule future reminders
            if ($scheduledAt->isFuture()) {
                $invoice->reminders()->create([
                    'type' => InvoiceReminderSchedule::typeForOffset($offset),
                    'days_offset' => $offset,
                    'scheduled_at' => $scheduledAt,
                    'status' => self::STATUS_PENDING,
                ]);
            }
        }
    }
}
