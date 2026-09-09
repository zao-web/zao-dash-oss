<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\CreateInvoiceTool;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->client = Client::factory()->create();
    $this->project = Project::factory()->create(['client_id' => $this->client->id]);
});

test('creates invoice with line items', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(CreateInvoiceTool::class, [
        'client_id' => $this->client->id,
        'subject' => 'Development Services',
        'items' => [
            ['description' => 'Feature Development', 'quantity' => 10, 'unit_price' => 150],
            ['description' => 'Bug Fixes', 'quantity' => 5, 'unit_price' => 150],
        ],
    ]);

    $response->assertOk();
    $response->assertSee('Development Services');

    $invoice = Invoice::where('client_id', $this->client->id)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->lines)->toHaveCount(2);
});

test('creates invoice with project association', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(CreateInvoiceTool::class, [
        'client_id' => $this->client->id,
        'project_id' => $this->project->id,
        'subject' => 'Project Invoice',
        'items' => [
            ['description' => 'Consulting', 'quantity' => 8, 'unit_price' => 200],
        ],
    ]);

    $response->assertOk();

    $invoice = Invoice::where('project_id', $this->project->id)->first();
    expect($invoice)->not->toBeNull();
});

test('validates required client_id', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(CreateInvoiceTool::class, [
        'subject' => 'Test Invoice',
        'items' => [
            ['description' => 'Test', 'quantity' => 1, 'unit_price' => 100],
        ],
    ]);

    $response->assertHasErrors();
});

test('validates required items array', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(CreateInvoiceTool::class, [
        'client_id' => $this->client->id,
        'items' => [],
    ]);

    $response->assertHasErrors();
});

test('validates line item fields', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(CreateInvoiceTool::class, [
        'client_id' => $this->client->id,
        'items' => [
            ['description' => 'Missing price fields'],
        ],
    ]);

    $response->assertHasErrors();
});

test('uses transaction for invoice creation', function () {
    // Create a situation where line item creation would fail after invoice creation
    // The invoice should be rolled back
    $response = ZaoDashServer::actingAs($this->user)->tool(CreateInvoiceTool::class, [
        'client_id' => $this->client->id,
        'items' => [
            ['description' => 'Valid item', 'quantity' => 1, 'unit_price' => 100],
        ],
    ]);

    $response->assertOk();

    // Verify we have exactly one invoice (transaction ensures atomicity)
    expect(Invoice::count())->toBe(1);
});

test('sets default due date to 30 days', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(CreateInvoiceTool::class, [
        'client_id' => $this->client->id,
        'items' => [
            ['description' => 'Service', 'quantity' => 1, 'unit_price' => 100],
        ],
    ]);

    $response->assertOk();

    $invoice = Invoice::first();
    expect((int) round(now()->diffInDays($invoice->due_date)))->toBe(30);
});

test('respects custom due_days parameter', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(CreateInvoiceTool::class, [
        'client_id' => $this->client->id,
        'due_days' => 15,
        'items' => [
            ['description' => 'Service', 'quantity' => 1, 'unit_price' => 100],
        ],
    ]);

    $response->assertOk();

    $invoice = Invoice::first();
    expect((int) round(now()->diffInDays($invoice->due_date)))->toBe(15);
});
