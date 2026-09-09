<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QboCustomer>
 */
class QboCustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'qbo_connection_id' => \App\Models\QuickBooksConnection::factory(),
            'qbo_id' => $this->faker->unique()->numerify('####'),
            'display_name' => $this->faker->company(),
            'given_name' => $this->faker->firstName(),
            'family_name' => $this->faker->lastName(),
            'company_name' => $this->faker->company(),
            'email' => $this->faker->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'balance' => $this->faker->randomFloat(2, 0, 50000),
            'active' => $this->faker->boolean(90),
            'client_id' => null,
            'synced_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
