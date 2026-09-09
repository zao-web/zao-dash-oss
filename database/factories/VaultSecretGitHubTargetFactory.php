<?php

namespace Database\Factories;

use App\Models\GitHubRepo;
use App\Models\VaultSecret;
use App\Models\VaultSecretGitHubTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

class VaultSecretGitHubTargetFactory extends Factory
{
    protected $model = VaultSecretGitHubTarget::class;

    public function definition(): array
    {
        return [
            'github_repo_id' => GitHubRepo::factory(),
            'vault_secret_id' => VaultSecret::factory(),
            'environment' => $this->faker->randomElement([null, 'production', 'staging']),
            'github_secret_name' => strtoupper($this->faker->slug(2, '_')),
            'github_environment_name' => null,
            'is_managed' => true,
            'last_pushed_at' => null,
            'last_pushed_fingerprint' => null,
            'last_seen_github_updated_at' => null,
            'drift_status' => VaultSecretGitHubTarget::DRIFT_STATUS_UNKNOWN,
            'drift_detected_at' => null,
            'last_pushed_by_user_id' => null,
            'last_pushed_by_agent_run_id' => null,
        ];
    }

    public function inSync(): static
    {
        return $this->state(fn (array $attributes) => [
            'drift_status' => VaultSecretGitHubTarget::DRIFT_STATUS_IN_SYNC,
            'last_pushed_at' => now(),
            'last_pushed_fingerprint' => hash('sha256', $this->faker->uuid()),
        ]);
    }

    public function missingOnGitHub(): static
    {
        return $this->state(fn (array $attributes) => [
            'drift_status' => VaultSecretGitHubTarget::DRIFT_STATUS_MISSING_ON_GITHUB,
            'drift_detected_at' => now(),
        ]);
    }

    public function modifiedOnGitHub(): static
    {
        return $this->state(fn (array $attributes) => [
            'drift_status' => VaultSecretGitHubTarget::DRIFT_STATUS_MODIFIED_ON_GITHUB,
            'drift_detected_at' => now(),
        ]);
    }

    public function unmanaged(): static
    {
        return $this->state(fn (array $attributes) => ['is_managed' => false]);
    }

    public function forEnvironment(string $environment): static
    {
        return $this->state(fn (array $attributes) => ['environment' => $environment]);
    }

    public function forGitHubEnvironment(string $githubEnv): static
    {
        return $this->state(fn (array $attributes) => ['github_environment_name' => $githubEnv]);
    }
}
