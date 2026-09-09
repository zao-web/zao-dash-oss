<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AgentFactory extends Factory
{
    protected $model = Agent::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'description' => fake()->sentence(),
            'status' => 'active',
            'model' => fake()->randomElement(['opus', 'sonnet', 'haiku']),
            'requires_approval' => fake()->boolean(),
            'use_consortium' => false,
            'max_budget_usd' => fake()->randomFloat(2, 1, 50),
            'allowed_tools' => [],
            'system_prompt' => fake()->paragraph(),
            'is_dynamic' => true,
            'webhook_enabled' => false,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'paused',
        ]);
    }

    public function withApproval(): static
    {
        return $this->state(fn (array $attributes) => [
            'requires_approval' => true,
        ]);
    }

    public function withWebhook(): static
    {
        return $this->state(fn (array $attributes) => [
            'webhook_enabled' => true,
            'webhook_token' => hash('sha256', Str::random(64)),
        ]);
    }

    public function withConsortium(): static
    {
        return $this->state(fn (array $attributes) => [
            'use_consortium' => true,
        ]);
    }
}
