<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentTask;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AgentTaskFactory extends Factory
{
    protected $model = AgentTask::class;

    public function definition(): array
    {
        return [
            'agent_id' => Agent::factory(),
            'task_id' => null,
            'project_id' => null,
            'client_id' => null,
            'task_description' => fake()->paragraph(),
            'workspace_path' => null,
            'context' => [],
            'priority' => fake()->randomElement(['low', 'normal', 'high', 'urgent']),
            'status' => AgentTask::STATUS_PENDING,
            'assigned_by' => null,
            'retry_attempt' => null,
            'retry_due_at' => null,
            'last_error' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentTask::STATUS_PENDING,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentTask::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentTask::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'result' => ['success' => true],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AgentTask::STATUS_FAILED,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'result' => ['error' => 'Test error'],
        ]);
    }

    public function forTask(Task $task): static
    {
        return $this->state(fn (array $attributes) => [
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'client_id' => $task->project?->client_id,
        ]);
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn (array $attributes) => [
            'project_id' => $project->id,
            'client_id' => $project->client_id,
        ]);
    }

    public function withAssigner(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_by' => $user->id,
        ]);
    }

    public function urgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => AgentTask::PRIORITY_URGENT,
        ]);
    }
}
