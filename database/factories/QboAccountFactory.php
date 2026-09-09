<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\QboAccount>
 */
class QboAccountFactory extends Factory
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
            'name' => $this->faker->words(3, true),
            'account_type' => $this->faker->randomElement(['Bank', 'Income', 'Expense', 'Asset', 'Liability']),
            'account_sub_type' => $this->faker->word(),
            'current_balance' => $this->faker->randomFloat(2, 0, 100000),
            'active' => $this->faker->boolean(90),
            'synced_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
