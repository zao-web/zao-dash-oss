<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GitHubRepo>
 */
class GitHubRepoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->slug(2);
        $owner = $this->faker->userName();

        return [
            'installation_id' => \App\Models\GitHubInstallation::factory(),
            'repo_id' => $this->faker->unique()->randomNumber(8),
            'owner' => $owner,
            'name' => $name,
            'full_name' => "{$owner}/{$name}",
            'is_private' => $this->faker->boolean(30),
            'default_branch' => 'main',
            'monitoring_enabled' => true,
            'deployment_config' => null,
        ];
    }

    /**
     * Indicate that monitoring is disabled.
     */
    public function notMonitored(): static
    {
        return $this->state(fn (array $attributes) => [
            'monitoring_enabled' => false,
        ]);
    }

    /**
     * Indicate that the repo has deployment config.
     */
    public function withDeploymentConfig(): static
    {
        return $this->state(fn (array $attributes) => [
            'deployment_config' => [
                'env' => 'production',
                'command' => 'deploy.sh',
            ],
        ]);
    }
}
