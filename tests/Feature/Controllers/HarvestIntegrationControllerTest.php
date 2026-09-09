<?php

use App\Models\HarvestCredential;
use App\Models\HarvestProject;
use App\Models\User;
use App\Services\Harvest\HarvestApiService;
use App\Services\Harvest\HarvestOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->oauthService = Mockery::mock(HarvestOAuthService::class);
    $this->apiService = Mockery::mock(HarvestApiService::class);

    $this->app->instance(HarvestOAuthService::class, $this->oauthService);
    $this->app->instance(HarvestApiService::class, $this->apiService);
});

// OAuth redirect tests
test('redirect generates harvest oauth url with state', function () {
    $authUrl = 'https://id.getharvest.com/oauth2/authorize?client_id=123';

    $this->oauthService->shouldReceive('getAuthorizationUrl')
        ->once()
        ->with(Mockery::type('string'))
        ->andReturn($authUrl);

    $response = $this->get('/integrations/harvest/redirect');

    $response->assertRedirect($authUrl);
    expect(session('harvest_oauth_state'))->not->toBeNull();
});

// OAuth callback tests
test('callback validates state parameter', function () {
    session(['harvest_oauth_state' => 'valid-state']);

    $response = $this->get('/integrations/harvest/callback?state=invalid-state&code=auth-code');

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('error', 'Invalid OAuth state');
});

test('callback exchanges code for tokens and stores credentials', function () {
    $state = 'valid-state';
    session(['harvest_oauth_state' => $state]);

    $tokens = [
        'access_token' => 'harvest.access.token',
        'refresh_token' => 'harvest.refresh.token',
        'expires_in' => 3600,
    ];

    $accounts = [
        [
            'id' => 12345,
            'name' => 'Test Company',
        ],
    ];

    $this->oauthService->shouldReceive('exchangeCodeForTokens')
        ->once()
        ->with('auth-code')
        ->andReturn($tokens);

    $this->oauthService->shouldReceive('getAccounts')
        ->once()
        ->with($tokens['access_token'])
        ->andReturn($accounts);

    $this->oauthService->shouldReceive('storeCredentials')
        ->once()
        ->with(
            Mockery::on(fn ($u) => $u->id === $this->user->id),
            $tokens,
            $accounts[0]
        );

    $response = $this->get("/integrations/harvest/callback?state={$state}&code=auth-code");

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('success', 'Harvest connected successfully');
});

test('callback handles token exchange failure', function () {
    $state = 'valid-state';
    session(['harvest_oauth_state' => $state]);

    $this->oauthService->shouldReceive('exchangeCodeForTokens')
        ->once()
        ->andThrow(new Exception('Invalid authorization code'));

    $response = $this->get("/integrations/harvest/callback?state={$state}&code=invalid-code");

    $response->assertRedirect('/settings/integrations');
    $response->assertSessionHas('error');
    expect(session('error'))->toContain('Failed to connect Harvest');
});

// Disconnect tests
test('disconnect removes harvest credential', function () {
    $credential = HarvestCredential::factory()
        ->for($this->user)
        ->create();

    $response = $this->deleteJson('/api/integrations/harvest/disconnect');

    $response->assertStatus(200);
    $response->assertJsonPath('message', 'Harvest disconnected');
    expect(HarvestCredential::find($credential->id))->toBeNull();
});

test('disconnect handles missing credential gracefully', function () {
    $response = $this->deleteJson('/api/integrations/harvest/disconnect');

    $response->assertStatus(200);
    $response->assertJsonPath('message', 'Harvest disconnected');
});

// Status tests
test('status returns disconnected when no credential', function () {
    $response = $this->getJson('/api/integrations/harvest/status');

    $response->assertStatus(200);
    $response->assertJson([
        'connected' => false,
        'account_name' => null,
        'expires_at' => null,
    ]);
});

test('status returns connected with credential details', function () {
    $credential = HarvestCredential::factory()
        ->for($this->user)
        ->create([
            'account_name' => 'Test Company',
            'expires_at' => now()->addMonth(),
        ]);

    $response = $this->getJson('/api/integrations/harvest/status');

    $response->assertStatus(200);
    $response->assertJson([
        'connected' => true,
        'account_name' => 'Test Company',
    ]);
});

test('status requires authentication', function () {
    auth()->logout();

    $response = $this->getJson('/api/integrations/harvest/status');

    $response->assertStatus(401);
});

// Sync projects tests
test('sync projects calls api service', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $this->apiService->shouldReceive('syncProjects')
        ->once()
        ->with(Mockery::on(fn ($u) => $u->id === $this->user->id))
        ->andReturn(5);

    $response = $this->postJson('/api/integrations/harvest/sync-projects');

    $response->assertStatus(200);
    $response->assertJsonPath('synced', 5);
});

// Sync task categories tests
test('sync task categories calls api service', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $this->apiService->shouldReceive('syncTaskCategories')
        ->once()
        ->andReturn(3);

    $response = $this->postJson('/api/integrations/harvest/sync-task-categories');

    $response->assertStatus(200);
    $response->assertJsonPath('synced', 3);
});

// Sync time entries tests
test('sync time entries accepts date range', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $this->apiService->shouldReceive('syncTimeEntries')
        ->once()
        ->with(
            Mockery::on(fn ($u) => $u->id === $this->user->id),
            '2024-01-01',
            '2024-01-31'
        )
        ->andReturn(10);

    $response = $this->postJson('/api/integrations/harvest/sync-time-entries', [
        'from' => '2024-01-01',
        'to' => '2024-01-31',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('synced', 10);
});

test('sync time entries validates date format', function () {
    $response = $this->postJson('/api/integrations/harvest/sync-time-entries', [
        'from' => 'invalid-date',
    ]);

    $response->assertStatus(422);
});

test('sync time entries works without date range', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $this->apiService->shouldReceive('syncTimeEntries')
        ->once()
        ->with(
            Mockery::on(fn ($u) => $u->id === $this->user->id),
            null,
            null
        )
        ->andReturn(15);

    $response = $this->postJson('/api/integrations/harvest/sync-time-entries');

    $response->assertStatus(200);
    $response->assertJsonPath('synced', 15);
});

// Sync invoices tests
test('sync invoices accepts date range', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $this->apiService->shouldReceive('syncInvoices')
        ->once()
        ->with(
            Mockery::on(fn ($u) => $u->id === $this->user->id),
            '2024-01-01',
            '2024-01-31'
        )
        ->andReturn(7);

    $response = $this->postJson('/api/integrations/harvest/sync-invoices', [
        'from' => '2024-01-01',
        'to' => '2024-01-31',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('synced', 7);
});

// Sync all tests
test('sync all syncs all harvest data', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $this->apiService->shouldReceive('syncProjects')
        ->once()
        ->andReturn(5);

    $this->apiService->shouldReceive('syncTaskCategories')
        ->once()
        ->andReturn(3);

    $this->apiService->shouldReceive('syncTimeEntries')
        ->once()
        ->andReturn(10);

    $this->apiService->shouldReceive('syncInvoices')
        ->once()
        ->andReturn(2);

    $response = $this->postJson('/api/integrations/harvest/sync-all');

    $response->assertStatus(200);
    $response->assertJson([
        'synced' => [
            'projects' => 5,
            'task_categories' => 3,
            'time_entries' => 10,
            'invoices' => 2,
        ],
    ]);
});

// Timer tests
test('start timer creates time entry', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $entryData = [
        'id' => 123,
        'hours' => 0,
        'is_running' => true,
    ];

    $this->apiService->shouldReceive('createTimeEntry')
        ->once()
        ->with(
            Mockery::on(fn ($u) => $u->id === $this->user->id),
            Mockery::on(fn ($data) => $data['project_id'] === 456 &&
                $data['task_id'] === 789 &&
                $data['notes'] === 'Working on feature'
            )
        )
        ->andReturn($entryData);

    $response = $this->postJson('/api/integrations/harvest/timers/start', [
        'harvest_project_id' => 456,
        'harvest_task_id' => 789,
        'notes' => 'Working on feature',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('id', 123);
});

test('start timer validates required fields', function () {
    $response = $this->postJson('/api/integrations/harvest/timers/start', [
        'notes' => 'Missing project and task',
    ]);

    $response->assertStatus(422);
});

test('stop timer stops running timer', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $entryData = [
        'id' => 123,
        'hours' => 2.5,
        'is_running' => false,
    ];

    $this->apiService->shouldReceive('stopTimer')
        ->once()
        ->with(
            Mockery::on(fn ($u) => $u->id === $this->user->id),
            123
        )
        ->andReturn($entryData);

    $response = $this->patchJson('/api/integrations/harvest/timers/123/stop');

    $response->assertStatus(200);
    $response->assertJsonPath('is_running', false);
});

test('restart timer restarts stopped timer', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $entryData = [
        'id' => 456,
        'hours' => 0,
        'is_running' => true,
    ];

    $this->apiService->shouldReceive('restartTimer')
        ->once()
        ->with(
            Mockery::on(fn ($u) => $u->id === $this->user->id),
            123
        )
        ->andReturn($entryData);

    $response = $this->postJson('/api/integrations/harvest/timers/123/restart');

    $response->assertStatus(200);
    $response->assertJsonPath('is_running', true);
});

test('running timers returns active timers', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $timers = [
        ['id' => 123, 'is_running' => true],
        ['id' => 456, 'is_running' => true],
    ];

    $this->apiService->shouldReceive('getRunningTimers')
        ->once()
        ->andReturn($timers);

    $response = $this->getJson('/api/integrations/harvest/timers/running');

    $response->assertStatus(200);
    $response->assertJsonCount(2);
});

// Project linking tests
test('list harvest projects returns active projects', function () {
    HarvestProject::factory()
        ->count(3)
        ->state(['is_active' => true])
        ->create();

    HarvestProject::factory()
        ->state(['is_active' => false])
        ->create();

    $response = $this->getJson('/api/integrations/harvest/projects');

    $response->assertStatus(200);
    $response->assertJsonCount(3);
});

test('link project connects harvest project to internal project', function () {
    $harvestProject = HarvestProject::factory()->create();

    $this->apiService->shouldReceive('linkProject')
        ->once()
        ->with(123, 456, 789)
        ->andReturn($harvestProject);

    $response = $this->postJson('/api/integrations/harvest/projects/link', [
        'harvest_project_id' => 123,
        'project_id' => 456,
        'client_id' => 789,
    ]);

    $response->assertStatus(200);
});

test('link project validates project exists', function () {
    $response = $this->postJson('/api/integrations/harvest/projects/link', [
        'harvest_project_id' => 123,
        'project_id' => 99999, // Non-existent
    ]);

    $response->assertStatus(422);
});

// Report tests
test('profitability report validates required fields', function () {
    $response = $this->postJson('/api/integrations/harvest/reports/profitability', [
        'period_type' => 'monthly',
        // Missing from and to
    ]);

    $response->assertStatus(422);
});

test('profitability report validates period type', function () {
    $response = $this->postJson('/api/integrations/harvest/reports/profitability', [
        'period_type' => 'invalid',
        'from' => '2024-01-01',
        'to' => '2024-01-31',
    ]);

    $response->assertStatus(422);
});

test('profitability report calculates profitability', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $snapshot = [
        'billable_hours' => 100,
        'billable_amount' => 10000,
        'cost' => 6000,
        'profit' => 4000,
        'margin' => 40,
    ];

    $this->apiService->shouldReceive('calculateProfitability')
        ->once()
        ->with('monthly', '2024-01-01', '2024-01-31', null, null)
        ->andReturn($snapshot);

    $response = $this->postJson('/api/integrations/harvest/reports/profitability', [
        'period_type' => 'monthly',
        'from' => '2024-01-01',
        'to' => '2024-01-31',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('profit', 4000);
});

test('project time report requires date range', function () {
    $response = $this->postJson('/api/integrations/harvest/reports/project-time');

    $response->assertStatus(422);
});

test('project time report returns time by project', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $report = [
        ['project_id' => 1, 'hours' => 40],
        ['project_id' => 2, 'hours' => 60],
    ];

    $this->apiService->shouldReceive('getProjectTimeReport')
        ->once()
        ->andReturn($report);

    $response = $this->postJson('/api/integrations/harvest/reports/project-time', [
        'from' => '2024-01-01',
        'to' => '2024-01-31',
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(2);
});

test('team time report returns time by team member', function () {
    HarvestCredential::factory()->for($this->user)->create();

    $report = [
        ['user_id' => 1, 'hours' => 80],
        ['user_id' => 2, 'hours' => 120],
    ];

    $this->apiService->shouldReceive('getTeamTimeReport')
        ->once()
        ->andReturn($report);

    $response = $this->postJson('/api/integrations/harvest/reports/team-time', [
        'from' => '2024-01-01',
        'to' => '2024-01-31',
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(2);
});
