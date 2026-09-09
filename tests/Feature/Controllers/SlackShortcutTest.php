<?php

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SlackWorkspace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.slack.signing_secret' => null]);
    Http::fake([
        'https://slack.com/api/views.open' => Http::response(['ok' => true]),
        'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);
});

// ──────────────────────────────────────────────────
// Log Note — Global Shortcut
// ──────────────────────────────────────────────────

test('log note shortcut opens modal with client list', function () {
    $workspace = SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    Client::factory()->create(['name' => 'Acme Corp', 'status' => 'active']);

    $payload = [
        'type' => 'shortcut',
        'callback_id' => 'log_note_shortcut',
        'trigger_id' => 'trigger-123',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();
    $response->assertJson(['ok' => true]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'views.open')
            && $request['view']['callback_id'] === 'log_note_modal'
            && str_contains(json_encode($request['view']), 'Acme Corp');
    });
});

test('log note shortcut returns error when no active clients', function () {
    SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);

    $payload = [
        'type' => 'shortcut',
        'callback_id' => 'log_note_shortcut',
        'trigger_id' => 'trigger-123',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();
    $response->assertJsonPath('error', 'No active clients found');
});

test('log note modal submission creates client note', function () {
    $workspace = SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    $client = Client::factory()->create(['name' => 'Acme Corp']);
    User::factory()->create(['role' => 'admin']);

    $payload = [
        'type' => 'view_submission',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'view' => [
            'callback_id' => 'log_note_modal',
            'private_metadata' => json_encode(['channel_id' => 'C12345']),
            'state' => [
                'values' => [
                    'client_block' => [
                        'client_select' => [
                            'selected_option' => ['value' => (string) $client->id],
                        ],
                    ],
                    'note_block' => [
                        'note_content' => ['value' => 'Client approved the new design'],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();
    $response->assertJsonPath('response_action', 'clear');

    $this->assertDatabaseHas('client_notes', [
        'client_id' => $client->id,
        'content' => 'Client approved the new design',
    ]);
});

test('log note modal returns validation errors when fields empty', function () {
    SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);

    $payload = [
        'type' => 'view_submission',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'view' => [
            'callback_id' => 'log_note_modal',
            'private_metadata' => '{}',
            'state' => [
                'values' => [
                    'client_block' => [
                        'client_select' => ['selected_option' => null],
                    ],
                    'note_block' => [
                        'note_content' => ['value' => ''],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();
    $response->assertJsonPath('response_action', 'errors');
});

// ──────────────────────────────────────────────────
// Log Action Item from Message — Message Shortcut
// ──────────────────────────────────────────────────

test('log action item message shortcut opens note modal pre-filled', function () {
    $workspace = SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    Client::factory()->create(['name' => 'Test Client', 'status' => 'active']);

    $payload = [
        'type' => 'message_action',
        'callback_id' => 'log_action_item_from_message',
        'trigger_id' => 'trigger-456',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'message' => ['text' => 'We need to update the API docs by Friday'],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'views.open')
            && $request['view']['callback_id'] === 'log_note_modal'
            && str_contains(json_encode($request['view']), 'We need to update the API docs by Friday');
    });
});

// ──────────────────────────────────────────────────
// Create Invoice — Global Shortcut
// ──────────────────────────────────────────────────

test('create invoice shortcut opens modal with clients and projects', function () {
    $workspace = SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    Client::factory()->create(['name' => 'Billing Client', 'status' => 'active']);
    Project::factory()->create(['name' => 'Main Project', 'status' => 'active']);

    $payload = [
        'type' => 'shortcut',
        'callback_id' => 'create_invoice_shortcut',
        'trigger_id' => 'trigger-789',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'views.open')
            && $request['view']['callback_id'] === 'create_invoice_modal'
            && str_contains(json_encode($request['view']), 'Billing Client');
    });
});

test('create invoice modal submission creates invoice with line item', function () {
    SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    $client = Client::factory()->create(['name' => 'Billing Client']);
    $project = Project::factory()->create(['name' => 'Main Project']);
    User::factory()->create(['role' => 'admin']);

    $payload = [
        'type' => 'view_submission',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'view' => [
            'callback_id' => 'create_invoice_modal',
            'private_metadata' => '{}',
            'state' => [
                'values' => [
                    'client_block' => [
                        'client_select' => [
                            'selected_option' => ['value' => (string) $client->id],
                        ],
                    ],
                    'project_block' => [
                        'project_select' => [
                            'selected_option' => ['value' => (string) $project->id],
                        ],
                    ],
                    'subject_block' => [
                        'invoice_subject' => ['value' => 'March retainer'],
                    ],
                    'description_block' => [
                        'line_description' => ['value' => 'Monthly retainer work'],
                    ],
                    'amount_block' => [
                        'line_amount' => ['value' => '2500.00'],
                    ],
                    'quantity_block' => [
                        'line_quantity' => ['value' => '1'],
                    ],
                    'due_days_block' => [
                        'due_days' => ['value' => '30'],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();
    $response->assertJsonPath('response_action', 'clear');

    $this->assertDatabaseHas('invoices', [
        'client_id' => $client->id,
        'subject' => 'March retainer',
        'status' => 'draft',
    ]);

    $this->assertDatabaseHas('invoice_lines', [
        'description' => 'Monthly retainer work',
        'unit_price' => '2500.00',
    ]);
});

test('create invoice modal returns error for invalid amount', function () {
    SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    $client = Client::factory()->create();

    $payload = [
        'type' => 'view_submission',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'view' => [
            'callback_id' => 'create_invoice_modal',
            'private_metadata' => '{}',
            'state' => [
                'values' => [
                    'client_block' => [
                        'client_select' => [
                            'selected_option' => ['value' => (string) $client->id],
                        ],
                    ],
                    'project_block' => [
                        'project_select' => ['selected_option' => null],
                    ],
                    'subject_block' => [
                        'invoice_subject' => ['value' => 'Test invoice'],
                    ],
                    'description_block' => [
                        'line_description' => ['value' => 'Work performed'],
                    ],
                    'amount_block' => [
                        'line_amount' => ['value' => 'not-a-number'],
                    ],
                    'quantity_block' => [
                        'line_quantity' => ['value' => '1'],
                    ],
                    'due_days_block' => [
                        'due_days' => ['value' => '30'],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();
    $response->assertJsonPath('response_action', 'errors');
    $response->assertJsonPath('errors.amount_block', 'Please enter a valid amount');
});

// ──────────────────────────────────────────────────
// Create Lead from Message — Message Shortcut
// ──────────────────────────────────────────────────

test('create lead message shortcut opens modal pre-filled from message', function () {
    $workspace = SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);

    $payload = [
        'type' => 'message_action',
        'callback_id' => 'create_lead_from_message',
        'trigger_id' => 'trigger-abc',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'channel' => ['id' => 'C12345'],
        'message' => ['text' => 'Got a referral from John at Acme Corp, interested in website redesign'],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'views.open')
            && $request['view']['callback_id'] === 'create_lead_modal'
            && str_contains(json_encode($request['view']), 'Got a referral from John at Acme Corp');
    });
});

test('create lead modal submission creates lead', function () {
    SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);
    User::factory()->create(['role' => 'admin']);

    $payload = [
        'type' => 'view_submission',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'view' => [
            'callback_id' => 'create_lead_modal',
            'private_metadata' => json_encode(['channel_id' => 'C12345']),
            'state' => [
                'values' => [
                    'company_block' => [
                        'company_name' => ['value' => 'Acme Corp'],
                    ],
                    'contact_block' => [
                        'contact_name' => ['value' => 'John Doe'],
                    ],
                    'email_block' => [
                        'contact_email' => ['value' => 'john@acme.com'],
                    ],
                    'website_block' => [
                        'lead_website' => ['value' => 'https://acme.com'],
                    ],
                    'deal_value_block' => [
                        'deal_value' => ['value' => '10000'],
                    ],
                    'notes_block' => [
                        'lead_notes' => ['value' => 'Referred by a mutual contact'],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();
    $response->assertJsonPath('response_action', 'clear');

    $this->assertDatabaseHas('leads', [
        'company_name' => 'Acme Corp',
        'contact_name' => 'John Doe',
        'contact_email' => 'john@acme.com',
        'stage' => 'new',
        'source' => 'slack',
    ]);
});

test('create lead modal returns error when company name empty', function () {
    SlackWorkspace::factory()->create(['workspace_id' => 'T12345']);

    $payload = [
        'type' => 'view_submission',
        'user' => ['id' => 'U12345'],
        'team' => ['id' => 'T12345'],
        'view' => [
            'callback_id' => 'create_lead_modal',
            'private_metadata' => '{}',
            'state' => [
                'values' => [
                    'company_block' => [
                        'company_name' => ['value' => ''],
                    ],
                    'contact_block' => [
                        'contact_name' => ['value' => null],
                    ],
                    'email_block' => [
                        'contact_email' => ['value' => null],
                    ],
                    'website_block' => [
                        'lead_website' => ['value' => null],
                    ],
                    'deal_value_block' => [
                        'deal_value' => ['value' => null],
                    ],
                    'notes_block' => [
                        'lead_notes' => ['value' => null],
                    ],
                ],
            ],
        ],
    ];

    $response = $this->post('/webhooks/slack/interactivity', [
        'payload' => json_encode($payload),
    ]);

    $response->assertOk();
    $response->assertJsonPath('response_action', 'errors');
    $response->assertJsonPath('errors.company_block', 'Company name is required');
});

// ──────────────────────────────────────────────────
// Modal Builder Unit Tests
// ──────────────────────────────────────────────────

test('logNoteModal builds correct structure with clients', function () {
    $service = new \App\Services\Slack\SlackBotResponseService;

    $clients = [
        ['id' => 1, 'name' => 'Client A'],
        ['id' => 2, 'name' => 'Client B'],
    ];

    $modal = $service->logNoteModal($clients, 'Pre-filled note');

    expect($modal['callback_id'])->toBe('log_note_modal')
        ->and($modal['blocks'])->toHaveCount(2)
        ->and($modal['blocks'][0]['element']['options'])->toHaveCount(2)
        ->and($modal['blocks'][1]['element']['initial_value'])->toBe('Pre-filled note');
});

test('createInvoiceModal builds correct structure with project selector', function () {
    $service = new \App\Services\Slack\SlackBotResponseService;

    $clients = [['id' => 1, 'name' => 'Client A']];
    $projects = [['id' => 10, 'name' => 'Project X']];

    $modal = $service->createInvoiceModal($clients, $projects);

    expect($modal['callback_id'])->toBe('create_invoice_modal')
        ->and($modal['blocks'])->toHaveCount(7); // client + project + subject + description + amount + quantity + due days
});

test('createInvoiceModal omits project selector when no projects', function () {
    $service = new \App\Services\Slack\SlackBotResponseService;

    $clients = [['id' => 1, 'name' => 'Client A']];

    $modal = $service->createInvoiceModal($clients, []);

    expect($modal['callback_id'])->toBe('create_invoice_modal')
        ->and($modal['blocks'])->toHaveCount(6); // no project block
});

test('createLeadModal builds correct structure with pre-filled notes', function () {
    $service = new \App\Services\Slack\SlackBotResponseService;

    $modal = $service->createLeadModal('Acme Corp', 'From a referral');

    expect($modal['callback_id'])->toBe('create_lead_modal')
        ->and($modal['blocks'])->toHaveCount(6)
        ->and($modal['blocks'][0]['element']['initial_value'])->toBe('Acme Corp')
        ->and($modal['blocks'][5]['element']['initial_value'])->toBe('From a referral');
});
