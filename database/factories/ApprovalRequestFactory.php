<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ApprovalRequest>
 */
class ApprovalRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $actionTypes = ['send_email', 'publish_content', 'update_client_data', 'deploy_code'];
        $actionType = fake()->randomElement($actionTypes);

        return [
            'agent_run_id' => \App\Models\AgentRun::factory(),
            'action_type' => $actionType,
            'description' => fake()->sentence(),
            'payload' => [
                'action' => $actionType,
                'details' => fake()->sentence(),
            ],
            'risk_level' => fake()->randomElement(['low', 'medium', 'high']),
            'status' => 'pending',
            'decided_by' => null,
            'decided_at' => null,
            'decision_note' => null,
            'expires_at' => now()->addHours(24),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'decided_by' => \App\Models\User::factory(),
            'decided_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'decided_by' => \App\Models\User::factory(),
            'decided_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'decided_by' => null,
            'decided_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'expires_at' => now()->subHours(1),
        ]);
    }
}
