<?php

namespace Database\Factories;

use App\Models\AgentRun;
use App\Models\InteractionRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class InteractionRequestFactory extends Factory
{
    protected $model = InteractionRequest::class;

    public function definition(): array
    {
        return [
            'agent_run_id' => AgentRun::factory(),
            'question_type' => fake()->randomElement(['text', 'select', 'confirm']),
            'question_content' => fake()->sentence().'?',
            'options' => null,
            'context' => [],
            'response' => null,
            'responded_at' => null,
            'responded_via' => null,
            'responded_by_id' => null,
            'expires_at' => now()->addMinutes(5),
        ];
    }

    public function text(): static
    {
        return $this->state(fn (array $attributes) => [
            'question_type' => 'text',
            'options' => null,
        ]);
    }

    public function select(): static
    {
        return $this->state(fn (array $attributes) => [
            'question_type' => 'select',
            'options' => [
                ['label' => 'Option A', 'description' => 'First option'],
                ['label' => 'Option B', 'description' => 'Second option'],
                ['label' => 'Option C', 'description' => 'Third option'],
            ],
        ]);
    }

    public function confirm(): static
    {
        return $this->state(fn (array $attributes) => [
            'question_type' => 'confirm',
            'question_content' => 'Do you want to proceed with this action?',
            'options' => null,
        ]);
    }

    public function responded(): static
    {
        return $this->state(fn (array $attributes) => [
            'response' => fake()->sentence(),
            'responded_at' => now(),
            'responded_via' => fake()->randomElement(['dashboard', 'slack', 'mcp']),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'response' => null,
            'responded_at' => null,
            'expires_at' => now()->subMinutes(5),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'response' => null,
            'responded_at' => null,
            'expires_at' => now()->addMinutes(5),
        ]);
    }

    public function withSlackContext(): static
    {
        return $this->state(fn (array $attributes) => [
            'context' => [
                'header' => 'Agent Question',
                'slack_channel' => 'C12345678',
                'slack_ts' => '1234567890.123456',
            ],
        ]);
    }
}
