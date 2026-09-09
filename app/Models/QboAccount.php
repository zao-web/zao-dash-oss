<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QboAccount extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'current_balance' => 'decimal:2',
        'active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(QuickBooksConnection::class, 'qbo_connection_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(QboTransaction::class, 'account_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('account_type', $type);
    }

    public function scopeBank($query)
    {
        return $query->where('account_type', 'Bank');
    }

    public function scopeIncome($query)
    {
        return $query->where('account_type', 'Income');
    }

    public function scopeExpense($query)
    {
        return $query->where('account_type', 'Expense');
    }
}
