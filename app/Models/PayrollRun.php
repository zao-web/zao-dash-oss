<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRun extends Model
{
    /** @use HasFactory<\Database\Factories\PayrollRunFactory> */
    use HasFactory;

    public const STATUS_PLANNED = 'planned';

    public const STATUS_COMPLETED = 'completed';

    public const DEPOSIT_STATUS_PENDING = 'pending';

    public const DEPOSIT_STATUS_COMPLETED = 'completed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'pay_date' => 'date',
            'gross_pay' => 'decimal:2',
            'federal_withholding' => 'decimal:2',
            'oregon_withholding' => 'decimal:2',
            'employee_fica' => 'decimal:2',
            'employer_fica' => 'decimal:2',
            'statewide_transit_tax' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'federal_deposit_amount' => 'decimal:2',
            'oregon_deposit_amount' => 'decimal:2',
            'federal_deposit_due' => 'date',
            'oregon_deposit_due' => 'date',
            'snapshot' => 'array',
            'completed_at' => 'datetime',
            'federal_deposit_completed_at' => 'datetime',
            'oregon_deposit_completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function taxProfile(): BelongsTo
    {
        return $this->belongsTo(TaxProfile::class);
    }
}
