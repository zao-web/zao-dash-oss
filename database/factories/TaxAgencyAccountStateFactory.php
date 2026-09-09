<?php

namespace Database\Factories;

use App\Models\TaxAgencyAccountState;
use App\Models\TaxAgencyConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaxAgencyAccountState>
 */
class TaxAgencyAccountStateFactory extends Factory
{
    protected $model = TaxAgencyAccountState::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();
        $agencyCode = fake()->randomElement(['irs', 'oregon_dor']);

        return [
            'user_id' => $user,
            'tax_agency_connection_id' => TaxAgencyConnection::factory()->state([
                'user_id' => $user,
                'agency_code' => $agencyCode,
                'agency_name' => $agencyCode === 'irs' ? 'Internal Revenue Service' : 'Oregon Department of Revenue',
                'portal_name' => $agencyCode === 'irs' ? 'IRS Individual Online Account' : 'Revenue Online',
            ]),
            'agency_code' => $agencyCode,
            'record_type' => fake()->randomElement(['balance', 'payment', 'account_status']),
            'record_key' => fake()->unique()->slug(3),
            'tax_year' => (int) fake()->year(),
            'label' => fake()->randomElement(['Balance due', 'Estimated payment received', 'Account standing']),
            'status' => fake()->randomElement(['due', 'paid', 'available']),
            'amount' => fake()->randomFloat(2, 0, 15000),
            'effective_date' => fake()->optional()->date(),
            'due_date' => fake()->optional()->date(),
            'payload' => ['source' => 'portal-sync'],
        ];
    }
}
