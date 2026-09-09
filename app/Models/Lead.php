<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'deal_value' => 'decimal:2',
        'expected_close_date' => 'date',
        'last_contacted_at' => 'datetime',
        'converted_at' => 'datetime',
        'tags' => 'array',
        'pages_viewed' => 'integer',
        'time_on_site_seconds' => 'integer',
        'max_scroll_depth' => 'integer',
    ];

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function convertedClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'converted_client_id');
    }

    public function seoPage(): BelongsTo
    {
        return $this->belongsTo(SeoPage::class);
    }

    public function rfpOpportunity(): BelongsTo
    {
        return $this->belongsTo(RfpOpportunity::class);
    }
}
