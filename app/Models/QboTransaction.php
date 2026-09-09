<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QboTransaction extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'txn_date' => 'date',
        'amount' => 'decimal:2',
        'is_reconciled' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(QuickBooksConnection::class, 'qbo_connection_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(QboAccount::class, 'account_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('txn_type', $type);
    }

    public function scopeIncome($query)
    {
        return $query->whereIn('txn_type', ['Invoice', 'Payment', 'SalesReceipt', 'Deposit']);
    }

    public function scopeExpenses($query)
    {
        return $query->whereIn('txn_type', ['Expense', 'Bill', 'BillPayment', 'Purchase']);
    }

    public function scopeInDateRange($query, $start, $end)
    {
        return $query->whereBetween('txn_date', [$start, $end]);
    }
}
