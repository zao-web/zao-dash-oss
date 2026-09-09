<?php

use App\Models\AgentRun;
use App\Models\User;
use App\Models\WebsiteProject;
use App\Models\WordPressSite;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('can create website project with required fields', function () {
    $project = WebsiteProject::factory()->create([
        'name' => 'Test Project',
        'project_type' => WebsiteProject::TYPE_AUTONOMOUS,
    ]);

    expect($project)->toBeInstanceOf(WebsiteProject::class)
        ->and($project->name)->toBe('Test Project')
        ->and($project->slug)->toBe('test-project')
        ->and($project->project_type)->toBe(WebsiteProject::TYPE_AUTONOMOUS)
        ->and($project->status)->toBe(WebsiteProject::STATUS_CREATED);
});

test('belongs to user', function () {
    $user = User::factory()->create();
    $project = WebsiteProject::factory()->create(['user_id' => $user->id]);

    expect($project->user)->toBeInstanceOf(User::class)
        ->and($project->user->id)->toBe($user->id);
});

test('belongs to wordpress site', function () {
    $site = WordPressSite::factory()->create();
    $project = WebsiteProject::factory()->create(['wordpress_site_id' => $site->id]);

    expect($project->wordpressSite)->toBeInstanceOf(WordPressSite::class)
        ->and($project->wordpressSite->id)->toBe($site->id);
});

test('can have many agent runs', function () {
    $project = WebsiteProject::factory()->create();
    $agentRun1 = AgentRun::factory()->create();
    $agentRun2 = AgentRun::factory()->create();

    $project->agentRuns()->attach($agentRun1->id, ['phase' => 'analyzing', 'order' => 1]);
    $project->agentRuns()->attach($agentRun2->id, ['phase' => 'building', 'order' => 2]);

    expect($project->agentRuns)->toHaveCount(2)
        ->and($project->agentRuns->first()->pivot->phase)->toBe('analyzing');
});

test('auto generates slug from name', function () {
    $project = WebsiteProject::factory()->create(['name' => 'My Awesome Project']);

    expect($project->slug)->toBe('my-awesome-project');
});

test('is autonomous returns true for autonomous projects', function () {
    $project = WebsiteProject::factory()->autonomous()->create();

    expect($project->isAutonomous())->toBeTrue()
        ->and($project->isGuided())->toBeFalse();
});

test('is guided returns true for guided projects', function () {
    $project = WebsiteProject::factory()->guided()->create();

    expect($project->isGuided())->toBeTrue()
        ->and($project->isAutonomous())->toBeFalse();
});

test('is migration returns true for migration projects', function () {
    $project = WebsiteProject::factory()->migration()->create();

    expect($project->isMigration())->toBeTrue()
        ->and($project->isRedesign())->toBeFalse();
});

test('is complete returns true when status is complete', function () {
    $project = WebsiteProject::factory()->complete()->create();

    expect($project->isComplete())->toBeTrue()
        ->and($project->hasFailed())->toBeFalse();
});

test('has failed returns true when status is failed', function () {
    $project = WebsiteProject::factory()->failed()->create();

    expect($project->hasFailed())->toBeTrue()
        ->and($project->isComplete())->toBeFalse();
});

test('is in progress returns true for active statuses', function () {
    $analyzingProject = WebsiteProject::factory()->analyzing()->create();
    $buildingProject = WebsiteProject::factory()->building()->create();
    $completeProject = WebsiteProject::factory()->complete()->create();

    expect($analyzingProject->isInProgress())->toBeTrue()
        ->and($buildingProject->isInProgress())->toBeTrue()
        ->and($completeProject->isInProgress())->toBeFalse();
});

test('can deploy returns true when ready for deployment', function () {
    $readyProject = WebsiteProject::factory()->create([
        'status' => WebsiteProject::STATUS_REVIEWING,
        'overall_progress' => 95,
        'staging_url' => 'https://example.com',
    ]);

    $notReadyProject = WebsiteProject::factory()->create([
        'status' => WebsiteProject::STATUS_BUILDING,
        'overall_progress' => 60,
    ]);

    expect($readyProject->canDeploy())->toBeTrue()
        ->and($notReadyProject->canDeploy())->toBeFalse();
});

test('update status sets started_at on first analyzing status', function () {
    $project = WebsiteProject::factory()->create(['status' => WebsiteProject::STATUS_CREATED]);

    expect($project->started_at)->toBeNull();

    $project->updateStatus(WebsiteProject::STATUS_ANALYZING);

    expect($project->fresh()->started_at)->not->toBeNull();
});

test('update status sets completed_at on complete or failed', function () {
    $completeProject = WebsiteProject::factory()->create();
    $failedProject = WebsiteProject::factory()->create();

    $completeProject->updateStatus(WebsiteProject::STATUS_COMPLETE);
    $failedProject->updateStatus(WebsiteProject::STATUS_FAILED);

    expect($completeProject->fresh()->completed_at)->not->toBeNull()
        ->and($failedProject->fresh()->completed_at)->not->toBeNull();
});

test('get progress percentage returns overall progress if set', function () {
    $project = WebsiteProject::factory()->create(['overall_progress' => 75]);

    expect($project->getProgressPercentage())->toBe(75);
});

test('get progress percentage calculates from status if overall progress is zero', function () {
    $createdProject = WebsiteProject::factory()->create(['status' => WebsiteProject::STATUS_CREATED]);
    $analyzingProject = WebsiteProject::factory()->create(['status' => WebsiteProject::STATUS_ANALYZING, 'overall_progress' => 0]);
    $completeProject = WebsiteProject::factory()->create(['status' => WebsiteProject::STATUS_COMPLETE, 'overall_progress' => 0]);

    expect($createdProject->getProgressPercentage())->toBe(0)
        ->and($analyzingProject->getProgressPercentage())->toBe(15)
        ->and($completeProject->getProgressPercentage())->toBe(100);
});

test('get live url returns production url when environment is production', function () {
    $project = WebsiteProject::factory()->create([
        'environment' => WebsiteProject::ENV_PRODUCTION,
        'staging_url' => 'https://staging.example.com',
        'production_url' => 'https://example.com',
    ]);

    expect($project->getLiveUrl())->toBe('https://example.com');
});

test('get live url returns staging url when environment is staging', function () {
    $project = WebsiteProject::factory()->create([
        'environment' => WebsiteProject::ENV_STAGING,
        'staging_url' => 'https://staging.example.com',
        'production_url' => null,
    ]);

    expect($project->getLiveUrl())->toBe('https://staging.example.com');
});

test('increment cost increases cost_incurred', function () {
    $project = WebsiteProject::factory()->create(['cost_incurred' => 10.00]);

    $project->incrementCost(5.50);

    expect($project->fresh()->cost_incurred)->toBe('15.50');
});

test('is over budget returns true when cost exceeds budget', function () {
    $overBudget = WebsiteProject::factory()->create([
        'budget_allocated' => 50.00,
        'cost_incurred' => 55.00,
    ]);

    $underBudget = WebsiteProject::factory()->create([
        'budget_allocated' => 50.00,
        'cost_incurred' => 30.00,
    ]);

    $noBudget = WebsiteProject::factory()->create([
        'budget_allocated' => null,
        'cost_incurred' => 100.00,
    ]);

    expect($overBudget->isOverBudget())->toBeTrue()
        ->and($underBudget->isOverBudget())->toBeFalse()
        ->and($noBudget->isOverBudget())->toBeFalse();
});

test('get remaining budget calculates correctly', function () {
    $project = WebsiteProject::factory()->create([
        'budget_allocated' => 100.00,
        'cost_incurred' => 35.00,
    ]);

    expect($project->getRemainingBudget())->toBe(65.00);
});

test('get remaining budget returns null when no budget allocated', function () {
    $project = WebsiteProject::factory()->create(['budget_allocated' => null]);

    expect($project->getRemainingBudget())->toBeNull();
});

test('scope of type filters by project type', function () {
    WebsiteProject::factory()->autonomous()->create();
    WebsiteProject::factory()->guided()->create();
    WebsiteProject::factory()->migration()->create();

    $autonomous = WebsiteProject::ofType(WebsiteProject::TYPE_AUTONOMOUS)->get();
    $guided = WebsiteProject::ofType(WebsiteProject::TYPE_GUIDED)->get();

    expect($autonomous)->toHaveCount(1)
        ->and($guided)->toHaveCount(1);
});

test('scope with status filters by status', function () {
    WebsiteProject::factory()->analyzing()->create();
    WebsiteProject::factory()->building()->create();
    WebsiteProject::factory()->complete()->create();

    $analyzing = WebsiteProject::withStatus(WebsiteProject::STATUS_ANALYZING)->get();

    expect($analyzing)->toHaveCount(1)
        ->and($analyzing->first()->status)->toBe(WebsiteProject::STATUS_ANALYZING);
});

test('scope active returns projects in progress', function () {
    WebsiteProject::factory()->analyzing()->create();
    WebsiteProject::factory()->building()->create();
    WebsiteProject::factory()->complete()->create();
    WebsiteProject::factory()->failed()->create();

    $active = WebsiteProject::active()->get();

    expect($active)->toHaveCount(2);
});

test('scope failed returns only failed projects', function () {
    WebsiteProject::factory()->analyzing()->create();
    WebsiteProject::factory()->failed()->count(2)->create();

    $failed = WebsiteProject::failed()->get();

    expect($failed)->toHaveCount(2);
});

test('scope complete returns only completed projects', function () {
    WebsiteProject::factory()->building()->create();
    WebsiteProject::factory()->complete()->count(3)->create();

    $complete = WebsiteProject::complete()->get();

    expect($complete)->toHaveCount(3);
});

test('scope for user filters by user id', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    WebsiteProject::factory()->count(3)->create(['user_id' => $user1->id]);
    WebsiteProject::factory()->count(2)->create(['user_id' => $user2->id]);

    $user1Projects = WebsiteProject::forUser($user1->id)->get();

    expect($user1Projects)->toHaveCount(3);
});

test('handles json fields correctly', function () {
    $project = WebsiteProject::factory()->create([
        'design_config' => ['colors' => ['primary' => '#FF0000']],
        'pages' => ['home', 'about'],
        'agent_runs' => [1, 2, 3],
    ]);

    $fresh = $project->fresh();

    expect($fresh->design_config)->toBeArray()
        ->and($fresh->design_config['colors']['primary'])->toBe('#FF0000')
        ->and($fresh->pages)->toBeArray()
        ->and($fresh->pages)->toHaveCount(2)
        ->and($fresh->agent_runs)->toBeArray()
        ->and($fresh->agent_runs)->toContain(1, 2, 3);
});

test('soft deletes project', function () {
    $project = WebsiteProject::factory()->create();
    $projectId = $project->id;

    $project->delete();

    expect(WebsiteProject::find($projectId))->toBeNull()
        ->and(WebsiteProject::withTrashed()->find($projectId))->not->toBeNull();
});
