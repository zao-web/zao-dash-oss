<?php

namespace Database\Factories;

use App\Models\GitHubRepo;
use App\Models\GitHubWorkflowSecretRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

class GitHubWorkflowSecretRequirementFactory extends Factory
{
    protected $model = GitHubWorkflowSecretRequirement::class;

    public function definition(): array
    {
        return [
            'github_repo_id' => GitHubRepo::factory(),
            'workflow_path' => '.github/workflows/'.$this->faker->slug(1).'.yml',
            'workflow_name' => $this->faker->words(2, true),
            'secret_name' => strtoupper($this->faker->slug(2, '_')),
            'is_required' => true,
            'job_name' => null,
            'job_environment_name' => null,
            'source' => GitHubWorkflowSecretRequirement::SOURCE_PARSED,
            'detected_at' => now(),
            'last_verified_at' => null,
            'vault_secret_id' => null,
            'match_status' => GitHubWorkflowSecretRequirement::MATCH_STATUS_UNMATCHED,
        ];
    }

    public function matched(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_status' => GitHubWorkflowSecretRequirement::MATCH_STATUS_MATCHED,
            'last_verified_at' => now(),
        ]);
    }

    public function unmatched(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_status' => GitHubWorkflowSecretRequirement::MATCH_STATUS_UNMATCHED,
            'vault_secret_id' => null,
        ]);
    }

    public function missingValue(): static
    {
        return $this->state(fn (array $attributes) => [
            'match_status' => GitHubWorkflowSecretRequirement::MATCH_STATUS_MISSING_VALUE,
        ]);
    }

    public function forWorkflow(string $path, ?string $name = null): static
    {
        return $this->state(fn (array $attributes) => [
            'workflow_path' => $path,
            'workflow_name' => $name,
        ]);
    }

    public function withJob(string $jobName, ?string $environmentName = null): static
    {
        return $this->state(fn (array $attributes) => [
            'job_name' => $jobName,
            'job_environment_name' => $environmentName,
        ]);
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => GitHubWorkflowSecretRequirement::SOURCE_MANUAL,
        ]);
    }

    public function agentInferred(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => GitHubWorkflowSecretRequirement::SOURCE_AGENT_INFERRED,
        ]);
    }
}
