<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Agents\AgentRegistry;
use App\Models\Agent;

test('command syncs agent definitions to database', function () {
    $registry = Mockery::mock(AgentRegistry::class);
    $this->app->instance(AgentRegistry::class, $registry);

    $mockDefinition = Mockery::mock(\App\Agents\Contracts\AgentDefinition::class);
    $mockDefinition->shouldReceive('metadata')
        ->andReturn([
            'id' => 'test-agent',
            'name' => 'Test Agent',
            'model' => 'sonnet',
            'trigger' => 'manual',
            'requires_approval' => false,
            'max_budget_usd' => 5.00,
        ]);

    $registry->shouldReceive('allDefinitions')
        ->once()
        ->andReturn(collect([$mockDefinition]));

    $registry->shouldReceive('syncToDatabase')
        ->once()
        ->andReturn([
            'created' => 1,
            'updated' => 0,
            'unchanged' => 0,
        ]);

    $registry->shouldReceive('getOutOfSyncAgents')
        ->once()
        ->andReturn(collect());

    $this->artisan('agents:sync')
        ->expectsOutput('Discovering agent definitions...')
        ->expectsOutput('Found 1 agent definition(s):')
        ->expectsOutput('Syncing to database...')
        ->expectsOutput('Sync complete:')
        ->assertExitCode(0);
});

test('command shows message when no definitions found', function () {
    $registry = Mockery::mock(AgentRegistry::class);
    $this->app->instance(AgentRegistry::class, $registry);

    $registry->shouldReceive('allDefinitions')
        ->once()
        ->andReturn(collect());

    $this->artisan('agents:sync')
        ->expectsOutput('No agent definitions found in app/Agents/Definitions/')
        ->assertExitCode(0);
});

test('dry run mode shows definitions without syncing', function () {
    $registry = Mockery::mock(AgentRegistry::class);
    $this->app->instance(AgentRegistry::class, $registry);

    $mockDefinition = Mockery::mock(\App\Agents\Contracts\AgentDefinition::class);
    $mockDefinition->shouldReceive('metadata')
        ->andReturn([
            'id' => 'test-agent',
            'name' => 'Test Agent',
            'model' => 'opus',
            'trigger' => 'webhook',
            'requires_approval' => true,
            'max_budget_usd' => 10.00,
        ]);

    $registry->shouldReceive('allDefinitions')
        ->once()
        ->andReturn(collect([$mockDefinition]));

    $registry->shouldNotReceive('syncToDatabase');

    $this->artisan('agents:sync', ['--dry-run' => true])
        ->expectsOutput('Dry run mode - no changes made')
        ->assertExitCode(0);
});

test('command displays agent definition details in table', function () {
    $registry = Mockery::mock(AgentRegistry::class);
    $this->app->instance(AgentRegistry::class, $registry);

    $mockDefinition = Mockery::mock(\App\Agents\Contracts\AgentDefinition::class);
    $mockDefinition->shouldReceive('metadata')
        ->andReturn([
            'id' => 'case-study-writer',
            'name' => 'Case Study Writer',
            'model' => 'opus',
            'trigger' => 'manual',
            'requires_approval' => true,
            'max_budget_usd' => 15.00,
        ]);

    $registry->shouldReceive('allDefinitions')
        ->once()
        ->andReturn(collect([$mockDefinition]));

    $registry->shouldReceive('syncToDatabase')
        ->once()
        ->andReturn(['created' => 1, 'updated' => 0, 'unchanged' => 0]);

    $registry->shouldReceive('getOutOfSyncAgents')
        ->once()
        ->andReturn(collect());

    $this->artisan('agents:sync')
        ->expectsOutputToContain('case-study-writer')
        ->expectsOutputToContain('Case Study Writer')
        ->expectsOutputToContain('opus')
        ->expectsOutputToContain('Yes')
        ->expectsOutputToContain('$15.00')
        ->assertExitCode(0);
});

test('command reports sync statistics', function () {
    $registry = Mockery::mock(AgentRegistry::class);
    $this->app->instance(AgentRegistry::class, $registry);

    $mockDefinition = Mockery::mock(\App\Agents\Contracts\AgentDefinition::class);
    $mockDefinition->shouldReceive('metadata')
        ->andReturn([
            'id' => 'test-agent',
            'name' => 'Test Agent',
            'model' => 'sonnet',
            'trigger' => 'manual',
            'requires_approval' => false,
            'max_budget_usd' => 5.00,
        ]);

    $registry->shouldReceive('allDefinitions')
        ->once()
        ->andReturn(collect([$mockDefinition]));

    $registry->shouldReceive('syncToDatabase')
        ->once()
        ->andReturn([
            'created' => 2,
            'updated' => 3,
            'unchanged' => 1,
        ]);

    $registry->shouldReceive('getOutOfSyncAgents')
        ->once()
        ->andReturn(collect());

    $this->artisan('agents:sync')
        ->expectsOutputToContain('Created: 2')
        ->expectsOutputToContain('Updated: 3')
        ->expectsOutputToContain('Unchanged: 1')
        ->assertExitCode(0);
});

test('command warns about out of sync agents', function () {
    $registry = Mockery::mock(AgentRegistry::class);
    $this->app->instance(AgentRegistry::class, $registry);

    $mockDefinition = Mockery::mock(\App\Agents\Contracts\AgentDefinition::class);
    $mockDefinition->shouldReceive('metadata')
        ->andReturn([
            'id' => 'test-agent',
            'name' => 'Test Agent',
            'model' => 'sonnet',
            'trigger' => 'manual',
            'requires_approval' => false,
            'max_budget_usd' => 5.00,
        ]);

    $outOfSyncAgent = new Agent([
        'id' => 1,
        'name' => 'Out of Sync Agent',
        'slug' => 'out-of-sync',
    ]);

    $registry->shouldReceive('allDefinitions')
        ->once()
        ->andReturn(collect([$mockDefinition]));

    $registry->shouldReceive('syncToDatabase')
        ->once()
        ->andReturn(['created' => 0, 'updated' => 0, 'unchanged' => 1]);

    $registry->shouldReceive('getOutOfSyncAgents')
        ->once()
        ->andReturn(collect([$outOfSyncAgent]));

    $this->artisan('agents:sync')
        ->expectsOutputToContain('Warning: 1 agent(s) may be out of sync')
        ->expectsOutputToContain('Out of Sync Agent (out-of-sync)')
        ->assertExitCode(0);
});

test('command shows multiple agent definitions', function () {
    $registry = Mockery::mock(AgentRegistry::class);
    $this->app->instance(AgentRegistry::class, $registry);

    $definitions = collect();

    for ($i = 1; $i <= 5; $i++) {
        $mockDef = Mockery::mock(\App\Agents\Contracts\AgentDefinition::class);
        $mockDef->shouldReceive('metadata')
            ->andReturn([
                'id' => "agent-{$i}",
                'name' => "Agent {$i}",
                'model' => 'sonnet',
                'trigger' => 'manual',
                'requires_approval' => false,
                'max_budget_usd' => 5.00,
            ]);
        $definitions->push($mockDef);
    }

    $registry->shouldReceive('allDefinitions')
        ->once()
        ->andReturn($definitions);

    $registry->shouldReceive('syncToDatabase')
        ->once()
        ->andReturn(['created' => 5, 'updated' => 0, 'unchanged' => 0]);

    $registry->shouldReceive('getOutOfSyncAgents')
        ->once()
        ->andReturn(collect());

    $this->artisan('agents:sync')
        ->expectsOutput('Found 5 agent definition(s):')
        ->assertExitCode(0);
});

test('command shows approval requirement correctly', function () {
    $registry = Mockery::mock(AgentRegistry::class);
    $this->app->instance(AgentRegistry::class, $registry);

    $mockDefinition = Mockery::mock(\App\Agents\Contracts\AgentDefinition::class);
    $mockDefinition->shouldReceive('metadata')
        ->andReturn([
            'id' => 'test-agent',
            'name' => 'Test Agent',
            'model' => 'sonnet',
            'trigger' => 'manual',
            'requires_approval' => false,
            'max_budget_usd' => 5.00,
        ]);

    $registry->shouldReceive('allDefinitions')
        ->once()
        ->andReturn(collect([$mockDefinition]));

    $registry->shouldReceive('syncToDatabase')
        ->once()
        ->andReturn(['created' => 1, 'updated' => 0, 'unchanged' => 0]);

    $registry->shouldReceive('getOutOfSyncAgents')
        ->once()
        ->andReturn(collect());

    $this->artisan('agents:sync')
        ->expectsOutputToContain('No')
        ->assertExitCode(0);
});

test('command handles force sync option', function () {
    $registry = Mockery::mock(AgentRegistry::class);
    $this->app->instance(AgentRegistry::class, $registry);

    $mockDefinition = Mockery::mock(\App\Agents\Contracts\AgentDefinition::class);
    $mockDefinition->shouldReceive('metadata')
        ->andReturn([
            'id' => 'test-agent',
            'name' => 'Test Agent',
            'model' => 'sonnet',
            'trigger' => 'manual',
            'requires_approval' => false,
            'max_budget_usd' => 5.00,
        ]);

    $registry->shouldReceive('allDefinitions')
        ->once()
        ->andReturn(collect([$mockDefinition]));

    $registry->shouldReceive('syncToDatabase')
        ->once()
        ->andReturn(['created' => 0, 'updated' => 1, 'unchanged' => 0]);

    $registry->shouldReceive('getOutOfSyncAgents')
        ->once()
        ->andReturn(collect());

    $this->artisan('agents:sync', ['--force' => true])
        ->assertExitCode(0);
});

test('command does not sync in dry run mode', function () {
    $registry = Mockery::mock(AgentRegistry::class);
    $this->app->instance(AgentRegistry::class, $registry);

    $mockDefinition = Mockery::mock(\App\Agents\Contracts\AgentDefinition::class);
    $mockDefinition->shouldReceive('metadata')
        ->andReturn([
            'id' => 'test-agent',
            'name' => 'Test Agent',
            'model' => 'sonnet',
            'trigger' => 'manual',
            'requires_approval' => false,
            'max_budget_usd' => 5.00,
        ]);

    $registry->shouldReceive('allDefinitions')
        ->once()
        ->andReturn(collect([$mockDefinition]));

    $registry->shouldNotReceive('syncToDatabase');
    $registry->shouldNotReceive('getOutOfSyncAgents');

    $this->artisan('agents:sync', ['--dry-run' => true])
        ->assertExitCode(0);
});
