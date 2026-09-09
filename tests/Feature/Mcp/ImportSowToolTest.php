<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\ImportSowTool;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Models\Task;
use App\Models\User;
use App\Services\SowParsingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
});

test('imports sow from google doc and links the slack channel', function () {
    $workspace = SlackWorkspace::factory()->create([
        'workspace_id' => 'TZAODASH',
        'workspace_name' => 'Zao',
    ]);

    $channel = SlackChannel::factory()->create([
        'workspace_id' => $workspace->id,
        'channel_id' => 'CNEWPROJ',
        'channel_name' => 'client-acme',
    ]);

    $this->mock(SowParsingService::class, function ($mock) {
        $mock->shouldReceive('parseDocuments')
            ->once()
            ->andReturn([
                'client' => [
                    'name' => 'Acme Corp',
                    'website' => 'https://acme.test',
                    'description' => 'New client',
                ],
                'contacts' => [
                    [
                        'name' => 'Jane Client',
                        'email' => 'jane@acme.test',
                        'role' => 'Marketing Lead',
                    ],
                ],
                'project' => [
                    'name' => 'Acme Launch',
                    'description' => 'Launch work',
                    'type' => 'project',
                    'budget' => 30000,
                    'start_date' => '2026-04-01',
                    'end_date' => '2026-05-31',
                ],
                'milestones' => [
                    [
                        'name' => 'Kickoff',
                        'description' => 'Start project',
                        'due_date' => '2026-04-05',
                        'tasks' => [
                            [
                                'title' => 'Run kickoff call',
                                'description' => 'Align stakeholders',
                                'priority' => 'high',
                                'estimated_hours' => 4,
                                'due_date' => '2026-04-03',
                                'subtasks' => [],
                            ],
                        ],
                    ],
                ],
                'invoices' => [
                    [
                        'subject' => 'Deposit',
                        'amount' => 15000,
                        'issue_date' => '2026-04-01',
                        'due_date' => '2026-04-07',
                    ],
                ],
            ]);
    });

    $response = ZaoDashServer::actingAs($this->user)->tool(ImportSowTool::class, [
        'google_doc_urls' => ['https://docs.google.com/document/d/abc123/edit'],
        'workspace_id' => 'TZAODASH',
        'channel_id' => 'CNEWPROJ',
        'link_to_channel' => true,
    ]);

    $response->assertOk();
    $response->assertSee('SOW imported and project scaffolded successfully.');

    $client = Client::where('name', 'Acme Corp')->firstOrFail();
    $project = Project::where('name', 'Acme Launch')->firstOrFail();

    expect($project->client_id)->toBe($client->id)
        ->and($project->start_date?->toDateString())->toBe('2026-04-01')
        ->and($project->end_date?->toDateString())->toBe('2026-05-31')
        ->and(Invoice::where('project_id', $project->id)->count())->toBe(1)
        ->and(Task::where('project_id', $project->id)->count())->toBe(1);

    $channel->refresh();
    $client->refresh();
    $project->refresh();

    expect($channel->client_id)->toBe($client->id)
        ->and($client->slack_channel_id)->toBe($channel->id)
        ->and($project->slack_channel_id)->toBe($channel->id);
});

test('supports preview only without provisioning records', function () {
    $this->mock(SowParsingService::class, function ($mock) {
        $mock->shouldReceive('parseDocuments')
            ->once()
            ->andReturn([
                'client' => ['name' => 'Preview Client'],
                'contacts' => [],
                'project' => [
                    'name' => 'Preview Project',
                    'type' => 'project',
                ],
                'milestones' => [
                    [
                        'name' => 'Preview Phase',
                        'tasks' => [
                            ['title' => 'Preview task', 'priority' => 'medium', 'subtasks' => []],
                        ],
                    ],
                ],
                'invoices' => [],
                'billing' => [],
            ]);
    });

    $response = ZaoDashServer::actingAs($this->user)->tool(ImportSowTool::class, [
        'content' => 'Approved SOW text',
        'preview_only' => true,
    ]);

    $response->assertOk();
    $response->assertSee('SOW parsed successfully');

    expect(Client::where('name', 'Preview Client')->exists())->toBeFalse()
        ->and(Project::where('name', 'Preview Project')->exists())->toBeFalse()
        ->and(Invoice::count())->toBe(0);
});

test('returns helpful error when no source content is provided', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(ImportSowTool::class, []);

    $response->assertOk();
    $response->assertSee('Provide either google_doc_urls or content.');
});
