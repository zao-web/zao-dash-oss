<?php

use App\Agents\Tools\GetStatsTool;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('getName returns correct name', function () {
    $tool = new GetStatsTool;
    expect($tool->name())->toBe('Get Statistics');
});

test('getDescription returns correct description', function () {
    $tool = new GetStatsTool;
    expect($tool->description())->toContain('statistics');
});

test('execute returns all stats by default', function () {
    $tool = new GetStatsTool;
    $result = $tool->execute([]);

    expect($result)->toHaveKeys(['projects', 'tasks', 'clients', 'agents', 'approvals']);
});

test('execute returns project stats', function () {
    Project::factory()->count(10)->create(['status' => 'active']);
    Project::factory()->count(5)->create(['status' => 'completed']);

    $tool = new GetStatsTool;
    $result = $tool->execute(['include' => ['projects']]);

    expect($result)->toHaveKey('projects')
        ->and($result['projects'])->toHaveKeys(['total', 'active', 'completed', 'total_budget'])
        ->and($result['projects']['total'])->toBe(15)
        ->and($result['projects']['active'])->toBe(10)
        ->and($result['projects']['completed'])->toBe(5);
});

test('execute returns task stats', function () {
    Task::factory()->count(5)->create(['status' => 'pending']);
    Task::factory()->count(3)->create(['status' => 'in_progress']);
    Task::factory()->count(2)->create(['status' => 'completed']);

    $tool = new GetStatsTool;
    $result = $tool->execute(['include' => ['tasks']]);

    expect($result)->toHaveKey('tasks')
        ->and($result['tasks'])->toHaveKeys(['total', 'pending', 'in_progress', 'completed'])
        ->and($result['tasks']['total'])->toBe(10)
        ->and($result['tasks']['pending'])->toBe(5)
        ->and($result['tasks']['in_progress'])->toBe(3)
        ->and($result['tasks']['completed'])->toBe(2);
});

test('execute counts overdue tasks', function () {
    Task::factory()->create([
        'status' => 'pending',
        'due_date' => now()->subDays(5),
    ]);
    Task::factory()->create([
        'status' => 'in_progress',
        'due_date' => now()->addDays(5),
    ]);

    $tool = new GetStatsTool;
    $result = $tool->execute(['include' => ['tasks']]);

    expect($result['tasks']['overdue'])->toBe(1);
});

test('execute counts tasks completed today', function () {
    Task::factory()->create([
        'status' => 'completed',
        'updated_at' => now(),
    ]);
    Task::factory()->create([
        'status' => 'completed',
        'updated_at' => now()->subDays(2),
    ]);

    $tool = new GetStatsTool;
    $result = $tool->execute(['include' => ['tasks']]);

    expect($result['tasks']['completed_today'])->toBe(1);
});

test('execute returns client stats', function () {
    Client::factory()->count(8)->create(['status' => 'active', 'health_score' => 85]);
    Client::factory()->count(2)->create(['status' => 'inactive']);

    $tool = new GetStatsTool;
    $result = $tool->execute(['include' => ['clients']]);

    expect($result)->toHaveKey('clients')
        ->and($result['clients'])->toHaveKeys(['total', 'active', 'avg_health_score'])
        ->and($result['clients']['total'])->toBe(10)
        ->and($result['clients']['active'])->toBe(8)
        ->and($result['clients']['avg_health_score'])->toBe(85.0);
});

test('execute returns agent stats', function () {
    Agent::factory()->count(5)->create(['status' => 'active']);
    AgentRun::factory()->count(3)->create(['created_at' => now()]);

    $tool = new GetStatsTool;
    $result = $tool->execute(['include' => ['agents']]);

    expect($result)->toHaveKey('agents')
        ->and($result['agents'])->toHaveKeys(['total', 'active', 'runs_today'])
        ->and($result['agents']['total'])->toBe(5)
        ->and($result['agents']['active'])->toBe(5)
        ->and($result['agents']['runs_today'])->toBe(3);
});

test('execute returns approval stats', function () {
    ApprovalRequest::factory()->count(4)->create(['status' => 'pending']);
    ApprovalRequest::factory()->count(2)->create([
        'status' => 'approved',
        'decided_at' => now(),
    ]);

    $tool = new GetStatsTool;
    $result = $tool->execute(['include' => ['approvals']]);

    expect($result)->toHaveKey('approvals')
        ->and($result['approvals'])->toHaveKeys(['pending', 'approved_today'])
        ->and($result['approvals']['pending'])->toBe(4)
        ->and($result['approvals']['approved_today'])->toBe(2);
});

test('execute respects include parameter', function () {
    $tool = new GetStatsTool;
    $result = $tool->execute(['include' => ['projects', 'tasks']]);

    expect($result)->toHaveKeys(['projects', 'tasks'])
        ->and($result)->not->toHaveKey('clients')
        ->and($result)->not->toHaveKey('agents');
});

test('execute calculates total project budget', function () {
    Project::factory()->create(['budget' => 10000]);
    Project::factory()->create(['budget' => 25000]);
    Project::factory()->create(['budget' => 15000]);

    $tool = new GetStatsTool;
    $result = $tool->execute(['include' => ['projects']]);

    expect($result['projects']['total_budget'])->toBe(50000);
});
