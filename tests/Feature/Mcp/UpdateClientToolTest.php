<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\GetClientTool;
use App\Mcp\Tools\UpdateClientTool;
use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->client = Client::factory()->create([
        'name' => 'Pacific Grove Real Estate',
        'billing_email' => 'billing@pgri.test',
        'billing_cc_emails' => 'ap@pgri.test',
        'payment_terms' => 'Net 30',
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 1800,
        'recurring_invoice_day' => 1,
        'recurring_invoice_auto_send' => true,
        'recurring_invoice_description' => 'Monthly Retainer',
        'recurring_invoice_in_advance' => false,
    ]);
    $this->project = Project::factory()->create([
        'client_id' => $this->client->id,
        'name' => 'PGRI Retainer',
        'status' => 'active',
    ]);
    $this->client->update(['recurring_invoice_project_id' => $this->project->id]);
});

test('update-client schema documents billing and recurring invoice fields', function () {
    $descriptor = app(UpdateClientTool::class)->toArray();
    $properties = $descriptor['inputSchema']['properties'] ?? [];

    expect($descriptor['name'])->toBe('update-client')
        ->and($descriptor['description'])->toContain('recurring invoice')
        ->and($descriptor['description'])->toContain('Omitted fields')
        ->and($descriptor['inputSchema']['required'] ?? [])->toContain('id');

    foreach ([
        'recurring_invoice_enabled',
        'recurring_invoice_amount',
        'recurring_invoice_day',
        'recurring_invoice_auto_send',
        'recurring_invoice_description',
        'recurring_invoice_project_id',
        'recurring_invoice_in_advance',
        'payment_terms',
        'billing_email',
        'billing_cc_emails',
    ] as $field) {
        expect($properties)->toHaveKey($field);
        expect($properties[$field]['description'] ?? '')->not->toBeEmpty();
    }

    expect($properties['recurring_invoice_day']['description'])->toContain('1-28')
        ->and($properties['recurring_invoice_description']['description'])->toContain('255')
        ->and($properties['recurring_invoice_enabled']['type'])->toBe('boolean')
        ->and($properties['recurring_invoice_auto_send']['type'])->toBe('boolean')
        ->and($properties['recurring_invoice_in_advance']['type'])->toBe('boolean')
        ->and($properties['payment_terms']['enum'])->toBe([
            'Due on Receipt',
            'Net 15',
            'Net 30',
            'Net 45',
            'Net 60',
        ]);

    foreach (['billing_email', 'billing_cc_emails', 'recurring_invoice_description', 'recurring_invoice_project_id'] as $clearable) {
        expect($properties[$clearable]['type'])->toContain('null');
    }
});

test('updates recurring invoice amount without wiping omitted fields', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(UpdateClientTool::class, [
        'id' => $this->client->id,
        'recurring_invoice_amount' => 2000,
    ]);

    $response->assertOk();
    $response->assertSee('2000');
    $response->assertSee('recurring_invoice_amount');

    $this->client->refresh();

    expect((float) $this->client->recurring_invoice_amount)->toBe(2000.0)
        ->and($this->client->recurring_invoice_enabled)->toBeTrue()
        ->and((int) $this->client->recurring_invoice_day)->toBe(1)
        ->and($this->client->recurring_invoice_auto_send)->toBeTrue()
        ->and($this->client->recurring_invoice_description)->toBe('Monthly Retainer')
        ->and($this->client->recurring_invoice_project_id)->toBe($this->project->id)
        ->and($this->client->recurring_invoice_in_advance)->toBeFalse()
        ->and($this->client->payment_terms)->toBe('Net 30')
        ->and($this->client->billing_email)->toBe('billing@pgri.test')
        ->and($this->client->billing_cc_emails)->toBe('ap@pgri.test');
});

test('enables recurring invoices with amount and day 15', function () {
    $sierra = Client::factory()->create([
        'name' => 'Sierra',
        'recurring_invoice_enabled' => false,
        'recurring_invoice_amount' => 0,
        'recurring_invoice_day' => 1,
        'recurring_invoice_auto_send' => false,
        'billing_email' => 'billing@sierra.test',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(UpdateClientTool::class, [
        'id' => $sierra->id,
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 1000,
        'recurring_invoice_day' => 15,
    ]);

    $response->assertOk();
    $response->assertSee('1000');
    $response->assertSee('15');

    $sierra->refresh();

    expect($sierra->recurring_invoice_enabled)->toBeTrue()
        ->and((float) $sierra->recurring_invoice_amount)->toBe(1000.0)
        ->and((int) $sierra->recurring_invoice_day)->toBe(15)
        ->and($sierra->recurring_invoice_auto_send)->toBeFalse()
        ->and($sierra->billing_email)->toBe('billing@sierra.test');

    $read = ZaoDashServer::actingAs($this->user)->tool(GetClientTool::class, [
        'id' => $sierra->id,
    ]);

    $read->assertOk();
    $read->assertSee('1000');
    $read->assertSee('15');
});

test('persists false booleans without wiping sibling recurring fields', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(UpdateClientTool::class, [
        'id' => $this->client->id,
        'recurring_invoice_enabled' => false,
        'recurring_invoice_auto_send' => false,
        'recurring_invoice_in_advance' => true,
    ]);

    $response->assertOk();

    $this->client->refresh();

    expect($this->client->recurring_invoice_enabled)->toBeFalse()
        ->and($this->client->recurring_invoice_auto_send)->toBeFalse()
        ->and($this->client->recurring_invoice_in_advance)->toBeTrue()
        ->and((float) $this->client->recurring_invoice_amount)->toBe(1800.0)
        ->and((int) $this->client->recurring_invoice_day)->toBe(1);
});

test('updates billing email, cc emails, payment terms, description, and project', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(UpdateClientTool::class, [
        'id' => $this->client->id,
        'billing_email' => 'accounts@pgri.test',
        'billing_cc_emails' => 'ap@pgri.test, controller@pgri.test',
        'payment_terms' => 'Net 15',
        'recurring_invoice_description' => 'PGRI Monthly Retainer - {month_year}',
        'recurring_invoice_project_id' => $this->project->id,
    ]);

    $response->assertOk();

    $this->client->refresh();

    expect($this->client->billing_email)->toBe('accounts@pgri.test')
        ->and($this->client->billing_cc_emails)->toBe('ap@pgri.test, controller@pgri.test')
        ->and($this->client->payment_terms)->toBe('Net 15')
        ->and($this->client->recurring_invoice_description)->toBe('PGRI Monthly Retainer - {month_year}')
        ->and($this->client->recurring_invoice_project_id)->toBe($this->project->id)
        ->and((float) $this->client->recurring_invoice_amount)->toBe(1800.0);
});

test('rejects a recurring invoice day outside 1-28', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(UpdateClientTool::class, [
        'id' => $this->client->id,
        'recurring_invoice_day' => 29,
    ]);

    $response->assertHasErrors();

    expect((int) $this->client->fresh()->recurring_invoice_day)->toBe(1);
});

test('rejects invalid payment terms', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(UpdateClientTool::class, [
        'id' => $this->client->id,
        'payment_terms' => 'Net 90',
    ]);

    $response->assertHasErrors();

    expect($this->client->fresh()->payment_terms)->toBe('Net 30');
});

test('rejects a recurring invoice project that belongs to another client', function () {
    $otherProject = Project::factory()->create([
        'name' => 'Other Client Work',
        'status' => 'active',
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(UpdateClientTool::class, [
        'id' => $this->client->id,
        'recurring_invoice_project_id' => $otherProject->id,
    ]);

    $response->assertSee('must belong to this client');

    expect($this->client->fresh()->recurring_invoice_project_id)->toBe($this->project->id);
});

test('still updates name without requiring billing fields', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(UpdateClientTool::class, [
        'id' => $this->client->id,
        'name' => 'Pacific Grove RI',
    ]);

    $response->assertOk();
    $response->assertSee('Pacific Grove RI');

    $this->client->refresh();

    expect($this->client->name)->toBe('Pacific Grove RI')
        ->and((float) $this->client->recurring_invoice_amount)->toBe(1800.0)
        ->and($this->client->billing_email)->toBe('billing@pgri.test');
});

test('rejects a recurring invoice description longer than 255 characters', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(UpdateClientTool::class, [
        'id' => $this->client->id,
        'recurring_invoice_description' => str_repeat('a', 256),
    ]);

    $response->assertHasErrors();

    expect($this->client->fresh()->recurring_invoice_description)->toBe('Monthly Retainer');
});

test('accepts a recurring invoice description of 255 characters', function () {
    $description = str_repeat('a', 255);

    $response = ZaoDashServer::actingAs($this->user)->tool(UpdateClientTool::class, [
        'id' => $this->client->id,
        'recurring_invoice_description' => $description,
    ]);

    $response->assertOk();

    expect($this->client->fresh()->recurring_invoice_description)->toBe($description);
});

test('clears {field} via JSON null and leaves siblings intact', function (string $field) {
    $response = ZaoDashServer::actingAs($this->user)->tool(UpdateClientTool::class, [
        'id' => $this->client->id,
        $field => null,
    ]);

    $response->assertOk();

    $this->client->refresh();

    expect($this->client->{$field})->toBeNull()
        ->and((float) $this->client->recurring_invoice_amount)->toBe(1800.0)
        ->and($this->client->recurring_invoice_enabled)->toBeTrue()
        ->and((int) $this->client->recurring_invoice_day)->toBe(1)
        ->and($this->client->recurring_invoice_auto_send)->toBeTrue();

    $siblings = [
        'billing_email' => 'billing@pgri.test',
        'billing_cc_emails' => 'ap@pgri.test',
        'recurring_invoice_description' => 'Monthly Retainer',
        'recurring_invoice_project_id' => $this->project->id,
    ];
    unset($siblings[$field]);

    foreach ($siblings as $sibling => $expected) {
        expect($this->client->{$sibling})->toBe($expected);
    }
})->with([
    'billing_email',
    'billing_cc_emails',
    'recurring_invoice_description',
    'recurring_invoice_project_id',
]);

test('omitting clearable fields while updating amount leaves them unchanged', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(UpdateClientTool::class, [
        'id' => $this->client->id,
        'recurring_invoice_amount' => 2000,
    ]);

    $response->assertOk();

    $this->client->refresh();

    expect($this->client->billing_email)->toBe('billing@pgri.test')
        ->and($this->client->billing_cc_emails)->toBe('ap@pgri.test')
        ->and($this->client->recurring_invoice_description)->toBe('Monthly Retainer')
        ->and($this->client->recurring_invoice_project_id)->toBe($this->project->id)
        ->and((float) $this->client->recurring_invoice_amount)->toBe(2000.0);
});
