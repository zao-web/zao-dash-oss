<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QboCustomer extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'balance' => 'decimal:2',
        'active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(QuickBooksConnection::class, 'qbo_connection_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeWithBalance($query)
    {
        return $query->where('balance', '>', 0);
    }

    public function scopeUnmatched($query)
    {
        return $query->whereNull('client_id');
    }

    public function matchToClient(int $clientId): void
    {
        $this->update(['client_id' => $clientId]);
    }
}
