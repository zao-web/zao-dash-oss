<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Models\Client;
use App\Models\HealthAlert;
use App\Services\HealthAlertEscalationService;

beforeEach(function () {
    $this->service = Mockery::mock(HealthAlertEscalationService::class);
    $this->app->instance(HealthAlertEscalationService::class, $this->service);
});

test('command processes escalations successfully', function () {
    $this->service->shouldReceive('processEscalations')
        ->once()
        ->andReturn([
            ['alert_id' => 1, 'client' => 'Test Client', 'new_level' => 1],
            ['alert_id' => 2, 'client' => 'Another Client', 'new_level' => 2],
        ]);

    $this->artisan('health:process-escalations')
        ->expectsOutput('Processing health alert escalations...')
        ->expectsOutput('Escalated 2 alerts:')
        ->expectsOutputToContain('Alert #1 (Test Client) → Level 1')
        ->expectsOutputToContain('Alert #2 (Another Client) → Level 2')
        ->assertExitCode(0);
});

test('command shows message when no alerts need escalation', function () {
    $this->service->shouldReceive('processEscalations')
        ->once()
        ->andReturn([]);

    $this->artisan('health:process-escalations')
        ->expectsOutput('No alerts needed escalation.')
        ->assertExitCode(0);
});

test('dry run shows alerts without escalating', function () {
    $client = Client::factory()->create(['name' => 'Test Corp']);

    $alert = HealthAlert::create([
        'client_id' => $client->id,
        'alert_type' => HealthAlert::TYPE_HEALTH_CRITICAL,
        'severity' => HealthAlert::SEVERITY_HIGH,
        'health_score' => 35.0,
        'previous_score' => 75.0,
        'description' => 'Health dropped',
        'status' => HealthAlert::STATUS_OPEN,
        'escalation_level' => 0,
        'next_escalation_at' => now()->subHour(),
    ]);

    $this->artisan('health:process-escalations', ['--dry-run' => true])
        ->expectsOutputToContain('Test Corp')
        ->expectsOutputToContain('Would escalate')
        ->assertExitCode(0);

    // Verify alert was not modified
    expect($alert->refresh()->escalation_level)->toBe(0);
});

test('dry run shows message when no alerts need escalation', function () {
    $this->artisan('health:process-escalations', ['--dry-run' => true])
        ->expectsOutput('No alerts need escalation.')
        ->assertExitCode(0);
});

test('dry run displays alert details in table', function () {
    $client = Client::factory()->create(['name' => 'Test Client']);

    HealthAlert::create([
        'client_id' => $client->id,
        'alert_type' => HealthAlert::TYPE_HEALTH_CRITICAL,
        'severity' => HealthAlert::SEVERITY_CRITICAL,
        'health_score' => 15.0,
        'previous_score' => 85.0,
        'description' => 'Critical health drop',
        'status' => HealthAlert::STATUS_OPEN,
        'escalation_level' => 0,
        'next_escalation_at' => now()->subHour(),
    ]);

    $this->artisan('health:process-escalations', ['--dry-run' => true])
        ->expectsOutputToContain('Alert ID')
        ->expectsOutputToContain('Client')
        ->expectsOutputToContain('Test Client')
        ->assertExitCode(0);
});

test('command outputs processing message', function () {
    $this->service->shouldReceive('processEscalations')
        ->once()
        ->andReturn([]);

    $this->artisan('health:process-escalations')
        ->expectsOutput('Processing health alert escalations...')
        ->assertExitCode(0);
});

test('command handles multiple escalations', function () {
    $escalations = [];
    for ($i = 1; $i <= 5; $i++) {
        $escalations[] = [
            'alert_id' => $i,
            'client' => "Client {$i}",
            'new_level' => $i % 3,
        ];
    }

    $this->service->shouldReceive('processEscalations')
        ->once()
        ->andReturn($escalations);

    $this->artisan('health:process-escalations')
        ->expectsOutput('Escalated 5 alerts:')
        ->assertExitCode(0);
});

test('command shows correct escalation levels', function () {
    $this->service->shouldReceive('processEscalations')
        ->once()
        ->andReturn([
            ['alert_id' => 1, 'client' => 'Client A', 'new_level' => 0],
            ['alert_id' => 2, 'client' => 'Client B', 'new_level' => 1],
            ['alert_id' => 3, 'client' => 'Client C', 'new_level' => 2],
            ['alert_id' => 4, 'client' => 'Client D', 'new_level' => 3],
        ]);

    $this->artisan('health:process-escalations')
        ->expectsOutputToContain('Level 0')
        ->expectsOutputToContain('Level 1')
        ->expectsOutputToContain('Level 2')
        ->expectsOutputToContain('Level 3')
        ->assertExitCode(0);
});

test('dry run does not call service processEscalations', function () {
    $this->service->shouldNotReceive('processEscalations');

    Client::factory()->create();

    $this->artisan('health:process-escalations', ['--dry-run' => true])
        ->assertExitCode(0);
});

test('command filters alerts needing escalation correctly in dry run', function () {
    $client = Client::factory()->create(['name' => 'Escalate Me']);
    $client2 = Client::factory()->create(['name' => 'Do Not Escalate']);

    // Alert that needs escalation
    HealthAlert::create([
        'client_id' => $client->id,
        'alert_type' => HealthAlert::TYPE_HEALTH_CRITICAL,
        'severity' => HealthAlert::SEVERITY_HIGH,
        'health_score' => 35.0,
        'previous_score' => 75.0,
        'description' => 'Needs escalation',
        'status' => HealthAlert::STATUS_OPEN,
        'escalation_level' => 0,
        'next_escalation_at' => now()->subHour(),
    ]);

    // Alert that doesn't need escalation (acknowledged)
    HealthAlert::create([
        'client_id' => $client2->id,
        'alert_type' => HealthAlert::TYPE_HEALTH_WARNING,
        'severity' => HealthAlert::SEVERITY_MEDIUM,
        'health_score' => 55.0,
        'previous_score' => 70.0,
        'description' => 'Already handled',
        'status' => HealthAlert::STATUS_ACKNOWLEDGED,
        'escalation_level' => 0,
        'next_escalation_at' => now()->addDay(),
    ]);

    $this->artisan('health:process-escalations', ['--dry-run' => true])
        ->expectsOutputToContain('Escalate Me')
        ->assertExitCode(0);
});
