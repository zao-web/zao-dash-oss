<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookkeepingAdjustment extends Model
{
    /** @use HasFactory<\Database\Factories\BookkeepingAdjustmentFactory> */
    use HasFactory;

    public const TYPE_CATEGORY_RECLASS = 'category_reclass';

    public const SOURCE_RULE = 'rule';

    public const SOURCE_AI = 'ai';

    public const STATUS_SUGGESTED = 'suggested';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_DISMISSED = 'dismissed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'context' => 'array',
            'applied_at' => 'datetime',
            'dismissed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PersonalTransaction::class, 'personal_transaction_id');
    }

    public function currentCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'current_category_id');
    }

    public function suggestedCategory(): BelongsTo
    {
        return $this->belongsTo(TransactionCategory::class, 'suggested_category_id');
    }
}
