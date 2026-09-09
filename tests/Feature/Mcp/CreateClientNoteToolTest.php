<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\CreateClientNoteTool;
use App\Models\Client;
use App\Models\ClientNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->adminUser = User::factory()->create(['role' => 'admin']);
    $this->client = Client::factory()->create();
});

test('creates client note with provided content', function () {
    $response = ZaoDashServer::actingAs($this->adminUser)->tool(CreateClientNoteTool::class, [
        'client_id' => $this->client->id,
        'content' => 'Had a productive meeting about the new project timeline.',
    ]);

    $response->assertOk();
    $response->assertSee('productive meeting');

    $note = ClientNote::where('client_id', $this->client->id)->first();
    expect($note)->not->toBeNull();
    expect($note->content)->toBe('Had a productive meeting about the new project timeline.');
});

test('defaults to admin user when user_id not provided', function () {
    $response = ZaoDashServer::actingAs($this->adminUser)->tool(CreateClientNoteTool::class, [
        'client_id' => $this->client->id,
        'content' => 'Note without explicit user',
    ]);

    $response->assertOk();

    $note = ClientNote::first();
    expect($note->user_id)->toBe($this->adminUser->id);
});

test('uses provided user_id when specified', function () {
    $specificUser = User::factory()->create(['role' => 'staff']);

    $response = ZaoDashServer::actingAs($this->adminUser)->tool(CreateClientNoteTool::class, [
        'client_id' => $this->client->id,
        'content' => 'Note with specific user',
        'user_id' => $specificUser->id,
    ]);

    $response->assertOk();

    $note = ClientNote::first();
    expect($note->user_id)->toBe($specificUser->id);
});

test('validates required client_id', function () {
    $response = ZaoDashServer::actingAs($this->adminUser)->tool(CreateClientNoteTool::class, [
        'content' => 'Note without client',
    ]);

    $response->assertHasErrors();
});

test('validates required content', function () {
    $response = ZaoDashServer::actingAs($this->adminUser)->tool(CreateClientNoteTool::class, [
        'client_id' => $this->client->id,
    ]);

    $response->assertHasErrors();
});

test('validates client exists', function () {
    $response = ZaoDashServer::actingAs($this->adminUser)->tool(CreateClientNoteTool::class, [
        'client_id' => 99999,
        'content' => 'Note for nonexistent client',
    ]);

    $response->assertHasErrors();
});

test('validates content max length', function () {
    $longContent = str_repeat('a', 5001);

    $response = ZaoDashServer::actingAs($this->adminUser)->tool(CreateClientNoteTool::class, [
        'client_id' => $this->client->id,
        'content' => $longContent,
    ]);

    $response->assertHasErrors();
});

test('returns error when no admin user exists and user_id not provided', function () {
    // Remove admin role from our user
    User::query()->update(['role' => 'client']);

    $response = ZaoDashServer::actingAs($this->adminUser)->tool(CreateClientNoteTool::class, [
        'client_id' => $this->client->id,
        'content' => 'Note without available admin',
    ]);

    $response->assertHasErrors();
});
