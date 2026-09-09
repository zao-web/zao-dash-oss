<?php

use App\Models\NotionConnection;
use App\Models\NotionPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('redirect initiates oauth flow', function () {
    $response = $this->get(route('notion.redirect'));

    $response->assertRedirect();
    expect(session()->has('notion_oauth_state'))->toBeTrue();
});

test('callback with invalid state fails', function () {
    $response = $this->get(route('notion.callback', [
        'code' => 'test-code',
        'state' => 'invalid-state',
    ]));

    $response->assertRedirect(route('settings.integrations'));
    $response->assertSessionHas('error');
});

test('callback with valid state connects notion', function () {
    Http::fake([
        'api.notion.com/*' => Http::response([
            'access_token' => 'test-token',
            'workspace_name' => 'Test Workspace',
            'workspace_id' => 'ws-123',
        ]),
    ]);

    session(['notion_oauth_state' => 'test-state']);

    $response = $this->get(route('notion.callback', [
        'code' => 'test-code',
        'state' => 'test-state',
    ]));

    $response->assertRedirect(route('settings.integrations'));
    $response->assertSessionHas('success');
});

test('can disconnect notion connection', function () {
    $connection = NotionConnection::factory()->create(['user_id' => $this->user->id]);

    $response = $this->delete(route('notion.disconnect', $connection));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseMissing('notion_connections', [
        'id' => $connection->id,
    ]);
});

test('sync triggers page sync', function () {
    Http::fake([
        'api.notion.com/*' => Http::response(['results' => []]),
    ]);

    $connection = NotionConnection::factory()->create(['user_id' => $this->user->id]);

    $response = $this->post(route('notion.sync', $connection));

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('sync handles errors gracefully', function () {
    Http::fake([
        'api.notion.com/*' => Http::response([], 500),
    ]);

    $connection = NotionConnection::factory()->create(['user_id' => $this->user->id]);

    $response = $this->post(route('notion.sync', $connection));

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

test('syncDatabase syncs database items', function () {
    Http::fake([
        'api.notion.com/*' => Http::response(['results' => []]),
    ]);

    $connection = NotionConnection::factory()->create(['user_id' => $this->user->id]);
    $database = NotionPage::factory()->create([
        'notion_connection_id' => $connection->id,
        'is_database' => true,
    ]);

    $response = $this->post(route('notion.syncDatabase', $database));

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('syncDatabase fails for non-database pages', function () {
    $connection = NotionConnection::factory()->create(['user_id' => $this->user->id]);
    $page = NotionPage::factory()->create([
        'notion_connection_id' => $connection->id,
        'is_database' => false,
    ]);

    $response = $this->post(route('notion.syncDatabase', $page));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'This is not a database');
});

test('pages endpoint returns pages and databases', function () {
    $connection = NotionConnection::factory()->create(['user_id' => $this->user->id]);

    NotionPage::factory()->create([
        'notion_connection_id' => $connection->id,
        'is_database' => false,
    ]);

    NotionPage::factory()->create([
        'notion_connection_id' => $connection->id,
        'is_database' => true,
    ]);

    $response = $this->get(route('notion.pages', $connection));

    $response->assertOk();
    $response->assertJsonStructure([
        'pages',
        'databases',
    ]);
});

test('notion requires authentication', function () {
    auth()->logout();

    $response = $this->get(route('notion.redirect'));

    $response->assertRedirect(route('login'));
});
