<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PersonalTransaction extends Model
{
    /** @use HasFactory<\Database\Factories\PersonalTransactionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'transaction_date' => 'date',
        'amount' => 'decimal:2',
        'is_recurring' => 'boolean',
        'is_tax_deductible' => 'boolean',
        'is_business_expense' => 'boolean',
        'owner_payment_reviewed_at' => 'datetime',
        'revenue_recognition_reviewed_at' => 'datetime',
        'inactive_at' => 'datetime',
        'tags' => 'array',
    ];

    public function personalAccount(): BelongsTo
    {
        return $this->belongsTo(PersonalAccount::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(PersonalAccount::class, 'personal_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'category_id');
    }

    public function debtPayment(): HasOne
    {
        return $this->hasOne(DebtPayment::class);
    }

    public function estimatedTaxPayment(): HasOne
    {
        return $this->hasOne(EstimatedTaxPayment::class, 'personal_transaction_id');
    }

    public function scopeByDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('transaction_date', [$from, $to]);
    }

    public function scopeByImportSource(Builder $query, string $source): Builder
    {
        return $query->where('import_source', $source);
    }

    public function scopeRecurring(Builder $query): Builder
    {
        return $query->where('is_recurring', true);
    }

    public function scopeTaxDeductible(Builder $query): Builder
    {
        return $query->where('is_tax_deductible', true);
    }

    public function scopeUncategorized(Builder $query): Builder
    {
        return $query->whereNull('category_id');
    }

    public function scopeWithoutInactive(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereNull("{$table}.inactive_at");
    }

    /**
     * Life lists and totals: skip Plaid tombstones and overlapping Teller dupes.
     */
    public function scopeVisibleOnLife(Builder $query): Builder
    {
        return $query
            ->withoutInactive()
            ->withoutOverlappingTellerDupes()
            ->whereHas('account', fn (Builder $accountQuery) => $accountQuery->whereNull('inactive_at'));
    }

    /**
     * Hide Teller rows that duplicate a Plaid row for the same bank event.
     * Match date, ABS(amount), and description/merchant — card/loan Plaid amounts stay
     * positive while Teller rows are raw-signed. The teller_* rows stay in the database.
     */
    public function scopeWithoutOverlappingTellerDupes(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $builder) use ($table): void {
            $builder
                ->whereNull("{$table}.plaid_transaction_id")
                ->orWhere("{$table}.plaid_transaction_id", 'not like', 'teller_%')
                ->orWhereNotExists(function ($sub) use ($table): void {
                    $sub->selectRaw('1')
                        ->from('personal_transactions as plaid_tx')
                        ->whereColumn('plaid_tx.personal_account_id', "{$table}.personal_account_id")
                        ->where('plaid_tx.plaid_transaction_id', 'like', 'plaid_%')
                        ->whereNull('plaid_tx.inactive_at')
                        ->whereColumn('plaid_tx.transaction_date', "{$table}.transaction_date")
                        ->whereRaw("ABS(plaid_tx.amount) = ABS({$table}.amount)")
                        ->where(function ($match) use ($table): void {
                            $match
                                ->whereRaw(self::overlappingTextSql("{$table}.description", 'plaid_tx.description'))
                                ->orWhereRaw(self::overlappingTextSql("{$table}.merchant_name", 'plaid_tx.merchant_name'))
                                ->orWhereRaw(self::overlappingTextSql("{$table}.description", 'plaid_tx.merchant_name'))
                                ->orWhereRaw(self::overlappingTextSql("{$table}.merchant_name", 'plaid_tx.description'));
                        });
                });
        });
    }

    protected static function overlappingTextSql(string $leftColumn, string $rightColumn): string
    {
        return sprintf(
            "NULLIF(LOWER(TRIM(COALESCE(%s, ''))), '') IS NOT NULL AND NULLIF(LOWER(TRIM(COALESCE(%s, ''))), '') IS NOT NULL AND LOWER(TRIM(%s)) = LOWER(TRIM(%s))",
            $leftColumn,
            $rightColumn,
            $leftColumn,
            $rightColumn
        );
    }
}
