<?php

namespace Database\Factories;

use App\Models\BookkeepingPeriodClose;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BookkeepingPeriodClose>
 */
class BookkeepingPeriodCloseFactory extends Factory
{
    protected $model = BookkeepingPeriodClose::class;

    public function definition(): array
    {
        $year = 2026;
        $month = 1;
        $startDate = Carbon::create($year, $month, 1)->startOfDay();
        $endDate = $startDate->copy()->endOfMonth();

        return [
            'user_id' => User::factory(),
            'tax_year' => $year,
            'period_month' => $month,
            'period_key' => $startDate->format('Y-m'),
            'period_start_date' => $startDate->toDateString(),
            'period_end_date' => $endDate->toDateString(),
            'status' => BookkeepingPeriodClose::STATUS_CLOSED,
            'ledger_source_code' => 'internal_cash_books',
            'notes' => null,
            'closed_at' => $endDate->copy()->addDay()->startOfDay(),
            'snapshot' => [
                'transaction_count' => 0,
                'uncategorized_inflow_count' => 0,
                'uncategorized_outflow_count' => 0,
            ],
        ];
    }
}
