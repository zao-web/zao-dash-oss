<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgentRunFactory extends Factory
{
    protected $model = AgentRun::class;

    public function definition(): array
    {
        $status = fake()->randomElement(['running', 'completed', 'failed', 'pending_approval']);

        return [
            'agent_id' => Agent::factory(),
            'session_id' => fake()->uuid(),
            'status' => $status,
            'task' => fake()->sentence(),
            'context' => [],
            'output' => $status === 'completed' ? ['result' => fake()->sentence()] : null,
            'cost_usd' => $status === 'completed' ? fake()->randomFloat(4, 0.01, 5) : 0,
            'invocation_source' => fake()->randomElement(['manual', 'api', 'webhook', 'scheduled']),
            'invoked_by' => 'user:1',
            'started_at' => now()->subMinutes(rand(1, 60)),
            'completed_at' => in_array($status, ['completed', 'failed']) ? now() : null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'output' => ['result' => fake()->sentence()],
            'cost_usd' => fake()->randomFloat(4, 0.01, 5),
            'input_tokens' => fake()->numberBetween(100, 3000),
            'output_tokens' => fake()->numberBetween(100, 2000),
            'started_at' => now()->subMinutes(rand(1, 60)),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'output' => ['error' => fake()->sentence()],
            'started_at' => now()->subMinutes(rand(1, 60)),
            'completed_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running',
            'output' => null,
            'cost_usd' => 0,
            'started_at' => now(),
            'completed_at' => null,
        ]);
    }

    public function pendingApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending_approval',
            'output' => null,
            'cost_usd' => 0,
            'started_at' => now(),
            'completed_at' => null,
        ]);
    }
}
