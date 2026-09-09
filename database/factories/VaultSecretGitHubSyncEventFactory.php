<?php

namespace Database\Factories;

use App\Models\GitHubRepo;
use App\Models\VaultSecretGitHubSyncEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

class VaultSecretGitHubSyncEventFactory extends Factory
{
    protected $model = VaultSecretGitHubSyncEvent::class;

    public function definition(): array
    {
        return [
            'github_repo_id' => GitHubRepo::factory(),
            'vault_secret_id' => null,
            'vault_secret_github_target_id' => null,
            'action' => $this->faker->randomElement([
                VaultSecretGitHubSyncEvent::ACTION_CHECK,
                VaultSecretGitHubSyncEvent::ACTION_PUSH,
            ]),
            'status' => VaultSecretGitHubSyncEvent::STATUS_SUCCESS,
            'error_message' => null,
            'github_secret_name' => strtoupper($this->faker->slug(2, '_')),
            'environment' => null,
            'github_environment_name' => null,
            'triggered_by_type' => VaultSecretGitHubSyncEvent::TRIGGERED_BY_SYSTEM,
            'triggered_by_id' => null,
            'triggered_by_name' => 'system',
            'metadata' => null,
            'created_at' => now(),
        ];
    }

    public function push(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => VaultSecretGitHubSyncEvent::ACTION_PUSH,
        ]);
    }

    public function check(): static
    {
        return $this->state(fn (array $attributes) => [
            'action' => VaultSecretGitHubSyncEvent::ACTION_CHECK,
        ]);
    }

    public function successful(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VaultSecretGitHubSyncEvent::STATUS_SUCCESS,
        ]);
    }

    public function failed(string $message = 'An error occurred'): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => VaultSecretGitHubSyncEvent::STATUS_FAILURE,
            'error_message' => $message,
        ]);
    }

    public function triggeredByUser(int $userId, string $userName): static
    {
        return $this->state(fn (array $attributes) => [
            'triggered_by_type' => VaultSecretGitHubSyncEvent::TRIGGERED_BY_USER,
            'triggered_by_id' => $userId,
            'triggered_by_name' => $userName,
        ]);
    }

    public function triggeredByAgent(int $agentRunId, string $agentName): static
    {
        return $this->state(fn (array $attributes) => [
            'triggered_by_type' => VaultSecretGitHubSyncEvent::TRIGGERED_BY_AGENT,
            'triggered_by_id' => $agentRunId,
            'triggered_by_name' => $agentName,
        ]);
    }
}
