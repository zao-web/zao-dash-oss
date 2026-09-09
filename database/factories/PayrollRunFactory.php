<?php

namespace Database\Factories;

use App\Models\PayrollRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    protected $model = PayrollRun::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $payDate = now()->startOfMonth()->endOfMonth();

        return [
            'user_id' => User::factory(),
            'tax_profile_id' => null,
            'tax_year' => (int) $payDate->year,
            'period_month' => (int) $payDate->month,
            'pay_frequency' => 'monthly',
            'status' => PayrollRun::STATUS_PLANNED,
            'pay_date' => $payDate->toDateString(),
            'gross_pay' => 4000,
            'federal_withholding' => 1200,
            'oregon_withholding' => 320,
            'employee_fica' => 306,
            'employer_fica' => 306,
            'statewide_transit_tax' => 4,
            'net_pay' => 2170,
            'federal_deposit_amount' => 1812,
            'oregon_deposit_amount' => 324,
            'federal_deposit_due' => $payDate->copy()->addMonthNoOverflow()->startOfMonth()->addDays(14)->toDateString(),
            'oregon_deposit_due' => $payDate->copy()->addMonthNoOverflow()->startOfMonth()->addDays(14)->toDateString(),
            'federal_deposit_status' => PayrollRun::DEPOSIT_STATUS_PENDING,
            'oregon_deposit_status' => PayrollRun::DEPOSIT_STATUS_PENDING,
            'snapshot' => [
                'annual_salary_source' => 'Reasonable salary setting',
            ],
        ];
    }
}
