<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contractor1099Data extends Model
{
    use HasFactory;

    protected $table = 'contractor_1099_data';

    protected $guarded = [];

    protected $casts = [
        'tax_year' => 'integer',
        'total_payments' => 'decimal:2',
        'requires_1099' => 'boolean',
        'has_w9' => 'boolean',
        'payment_breakdown' => 'array',
    ];

    // Status constants
    public const STATUS_PENDING = 'pending';

    public const STATUS_W9_NEEDED = 'w9_needed';

    public const STATUS_READY = 'ready';

    public const STATUS_FILED = 'filed';

    // 1099 threshold
    public const THRESHOLD_AMOUNT = 600.00;

    public function qboConnection(): BelongsTo
    {
        return $this->belongsTo(QuickBooksConnection::class, 'qbo_connection_id');
    }

    // Scopes
    public function scopeForYear($query, int $year)
    {
        return $query->where('tax_year', $year);
    }

    public function scopeRequires1099($query)
    {
        return $query->where('requires_1099', true);
    }

    public function scopeNeedsW9($query)
    {
        return $query->where('requires_1099', true)
            ->where('has_w9', false);
    }

    public function scopeReady($query)
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function scopeNotFiled($query)
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_W9_NEEDED, self::STATUS_READY]);
    }

    // Helpers
    public function determineStatus(): string
    {
        if (! $this->requires_1099) {
            return self::STATUS_PENDING;
        }

        if (! $this->has_w9) {
            return self::STATUS_W9_NEEDED;
        }

        return self::STATUS_READY;
    }

    public function updateStatus(): self
    {
        $this->update(['status' => $this->determineStatus()]);

        return $this;
    }

    public function markW9Received(string $tinType, string $tinLastFour): self
    {
        $this->update([
            'has_w9' => true,
            'tin_type' => $tinType,
            'tin_last_four' => $tinLastFour,
            'status' => self::STATUS_READY,
        ]);

        return $this;
    }

    public function markFiled(): self
    {
        $this->update(['status' => self::STATUS_FILED]);

        return $this;
    }

    public function getMaskedTinAttribute(): ?string
    {
        if (! $this->tin_last_four) {
            return null;
        }

        $prefix = $this->tin_type === 'ssn' ? 'XXX-XX-' : 'XX-XXX';

        return $prefix.$this->tin_last_four;
    }

    // Sync from QBO
    public static function syncFromQbo(QuickBooksConnection $connection, int $taxYear, array $vendorPayments): array
    {
        $results = [];

        foreach ($vendorPayments as $vendor) {
            $totalPayments = $vendor['total_payments'] ?? 0;
            $requires1099 = $totalPayments >= self::THRESHOLD_AMOUNT;

            $data = static::updateOrCreate(
                [
                    'qbo_connection_id' => $connection->id,
                    'tax_year' => $taxYear,
                    'vendor_id' => $vendor['vendor_id'],
                ],
                [
                    'vendor_name' => $vendor['vendor_name'],
                    'vendor_type' => $vendor['vendor_type'] ?? null,
                    'total_payments' => $totalPayments,
                    'requires_1099' => $requires1099,
                    'payment_breakdown' => $vendor['monthly_breakdown'] ?? null,
                ]
            );

            // Update status if not already filed
            if ($data->status !== self::STATUS_FILED) {
                $data->updateStatus();
            }

            $results[] = $data;
        }

        return $results;
    }
}
