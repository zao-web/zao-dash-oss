<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaxAgencyAccountState extends Model
{
    /** @use HasFactory<\Database\Factories\TaxAgencyAccountStateFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'effective_date' => 'date',
        'due_date' => 'date',
        'payload' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(TaxAgencyConnection::class, 'tax_agency_connection_id');
    }
}
