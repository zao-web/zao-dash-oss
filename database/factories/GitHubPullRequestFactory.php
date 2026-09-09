<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GitHubPullRequest>
 */
class GitHubPullRequestFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repo_id' => \App\Models\GitHubRepo::factory(),
            'pr_id' => $this->faker->unique()->randomNumber(8),
            'pr_number' => $this->faker->unique()->numberBetween(1, 999),
            'title' => $this->faker->sentence(),
            'body' => $this->faker->paragraph(),
            'state' => 'open',
            'author' => $this->faker->userName(),
            'head_branch' => 'feature/'.$this->faker->slug(2),
            'base_branch' => 'develop',
            'reviewers' => [],
            'approval_status' => 'pending',
            'checks_passed' => false,
            'merged_at' => null,
            'approval_request_id' => null,
            'qa_agent_run_id' => null,
        ];
    }

    /**
     * Indicate that the PR targets main.
     */
    public function targetsMain(): static
    {
        return $this->state(fn (array $attributes) => [
            'base_branch' => 'main',
        ]);
    }

    /**
     * Indicate that checks have passed.
     */
    public function checksPassed(): static
    {
        return $this->state(fn (array $attributes) => [
            'checks_passed' => true,
        ]);
    }

    /**
     * Indicate that the PR is merged.
     */
    public function merged(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'merged',
            'merged_at' => now(),
        ]);
    }
}
