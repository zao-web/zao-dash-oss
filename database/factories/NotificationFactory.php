<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        $type = fake()->randomElement(['agent_run', 'approval_needed', 'system', 'sync_complete']);
        $severity = fake()->randomElement(['info', 'success', 'warning', 'error']);

        return [
            'user_id' => fake()->boolean(50) ? User::factory() : null,
            'type' => $type,
            'title' => fake()->sentence(3),
            'message' => fake()->sentence(),
            'icon' => $this->getIconForSeverity($severity),
            'severity' => $severity,
            'action_url' => fake()->boolean(70) ? '/agents' : null,
            'action_label' => fake()->boolean(70) ? 'View' : null,
            'metadata' => [],
            'read_at' => null,
            'dismissed_at' => null,
        ];
    }

    public function unread(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => null,
        ]);
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => now()->subMinutes(rand(1, 120)),
        ]);
    }

    public function dismissed(): static
    {
        return $this->state(fn (array $attributes) => [
            'dismissed_at' => now()->subMinutes(rand(1, 60)),
        ]);
    }

    public function forUser(?int $userId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $userId,
        ]);
    }

    public function global(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }

    public function agentRun(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'agent_run',
            'icon' => '✅',
            'severity' => 'success',
        ]);
    }

    public function approvalNeeded(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'approval_needed',
            'icon' => '⚠️',
            'severity' => 'warning',
        ]);
    }

    protected function getIconForSeverity(string $severity): string
    {
        return match ($severity) {
            'success' => '✅',
            'warning' => '⚠️',
            'error' => '❌',
            default => 'ℹ️',
        };
    }
}
