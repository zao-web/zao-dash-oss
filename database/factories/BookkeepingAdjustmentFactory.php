<?php

namespace Database\Factories;

use App\Models\BookkeepingAdjustment;
use App\Models\PersonalTransaction;
use App\Models\TransactionCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BookkeepingAdjustment>
 */
class BookkeepingAdjustmentFactory extends Factory
{
    protected $model = BookkeepingAdjustment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'personal_transaction_id' => PersonalTransaction::factory(),
            'current_category_id' => TransactionCategory::factory()->expense(),
            'suggested_category_id' => TransactionCategory::factory()->transfer(),
            'tax_year' => (int) now()->year,
            'period_month' => (int) now()->month,
            'adjustment_type' => BookkeepingAdjustment::TYPE_CATEGORY_RECLASS,
            'source' => BookkeepingAdjustment::SOURCE_AI,
            'status' => BookkeepingAdjustment::STATUS_SUGGESTED,
            'confidence' => 0.78,
            'rationale' => 'Likely owner movement rather than an operating expense.',
            'context' => [
                'description' => 'Owner draw transfer',
            ],
        ];
    }
}
