<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SinkingFundContribution extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'contribution_date' => 'date',
    ];

    public function sinkingFund(): BelongsTo
    {
        return $this->belongsTo(SinkingFund::class);
    }
}
