<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VaultAccessLog>
 */
class VaultAccessLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vault_secret_id' => \App\Models\VaultSecret::factory(),
            'accessor_type' => $this->faker->randomElement([
                \App\Models\VaultAccessLog::TYPE_USER,
                \App\Models\VaultAccessLog::TYPE_AGENT,
                \App\Models\VaultAccessLog::TYPE_SYSTEM,
            ]),
            'accessor_id' => $this->faker->numberBetween(1, 100),
            'accessor_name' => $this->faker->name(),
            'action' => $this->faker->randomElement([
                \App\Models\VaultAccessLog::ACTION_READ,
                \App\Models\VaultAccessLog::ACTION_WRITE,
                \App\Models\VaultAccessLog::ACTION_DELETE,
                \App\Models\VaultAccessLog::ACTION_ROTATE,
            ]),
            'ip_address' => $this->faker->ipv4(),
            'user_agent' => $this->faker->userAgent(),
            'context' => [],
            'was_successful' => $this->faker->boolean(90),
            'failure_reason' => null,
            'created_at' => $this->faker->dateTimeBetween('-1 month', 'now'),
        ];
    }
}
