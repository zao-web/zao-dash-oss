<?php

namespace Database\Factories;

use App\Models\GitHubIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GitHubIssue>
 */
class GitHubIssueFactory extends Factory
{
    protected $model = GitHubIssue::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'repo_id' => \App\Models\GitHubRepo::factory(),
            'issue_id' => $this->faker->unique()->randomNumber(8),
            'issue_number' => $this->faker->unique()->numberBetween(1, 999),
            'title' => $this->faker->sentence(),
            'body' => $this->faker->paragraph(),
            'state' => 'open',
            'labels' => [],
            'assignees' => [],
            'closed_at' => null,
            'task_id' => null,
            'agent_run_id' => null,
        ];
    }

    /**
     * Indicate that the issue is closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'state' => 'closed',
            'closed_at' => now(),
        ]);
    }

    /**
     * Indicate that the issue has agent labels.
     */
    public function agentTask(): static
    {
        return $this->state(fn (array $attributes) => [
            'labels' => ['agent', 'enhancement'],
        ]);
    }

    /**
     * Indicate that the issue is assigned to someone.
     */
    public function assigned(): static
    {
        return $this->state(fn (array $attributes) => [
            'assignees' => [$this->faker->userName()],
        ]);
    }
}
