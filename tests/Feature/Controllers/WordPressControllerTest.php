<?php

use App\Models\ContentSuggestion;
use App\Models\User;
use App\Models\WordPressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can create wordpress site connection', function () {
    Http::fake([
        '*/wp-json/wp/v2/users/me' => Http::response(['id' => 1]),
    ]);

    $response = $this->post(route('wordpress.store'), [
        'name' => 'My Blog',
        'url' => 'https://blog.example.com',
        'username' => 'admin',
        'app_password' => 'test-password',
        'mcp_enabled' => false,
    ]);

    $response->assertRedirect(route('settings.integrations'));
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('wordpress_sites', [
        'user_id' => $this->user->id,
        'name' => 'My Blog',
        'url' => 'https://blog.example.com',
    ]);
});

test('create requires name', function () {
    $response = $this->post(route('wordpress.store'), [
        'url' => 'https://blog.example.com',
        'username' => 'admin',
        'app_password' => 'password',
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('create requires valid url', function () {
    $response = $this->post(route('wordpress.store'), [
        'name' => 'My Blog',
        'url' => 'not-a-url',
        'username' => 'admin',
        'app_password' => 'password',
    ]);

    $response->assertSessionHasErrors(['url']);
});

test('create tests connection before saving', function () {
    Http::fake([
        '*/wp-json/wp/v2/users/me' => Http::response([], 401),
    ]);

    $response = $this->post(route('wordpress.store'), [
        'name' => 'My Blog',
        'url' => 'https://blog.example.com',
        'username' => 'admin',
        'app_password' => 'bad-password',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');

    $this->assertDatabaseMissing('wordpress_sites', [
        'name' => 'My Blog',
    ]);
});

test('can disconnect wordpress site', function () {
    $site = WordPressSite::factory()->create(['user_id' => $this->user->id]);

    $response = $this->delete(route('wordpress.disconnect', $site));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $this->assertDatabaseMissing('wordpress_sites', [
        'id' => $site->id,
    ]);
});

test('sync syncs wordpress posts', function () {
    Http::fake([
        '*/wp-json/wp/v2/posts*' => Http::response([
            ['id' => 1, 'title' => ['rendered' => 'Post 1']],
        ]),
    ]);

    $site = WordPressSite::factory()->create(['user_id' => $this->user->id]);

    $response = $this->post(route('wordpress.sync', $site));

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('sync handles errors', function () {
    Http::fake([
        '*/wp-json/wp/v2/posts*' => Http::response([], 500),
    ]);

    $site = WordPressSite::factory()->create(['user_id' => $this->user->id]);

    $response = $this->post(route('wordpress.sync', $site));

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

test('discoverCapabilities requires mcp enabled', function () {
    $site = WordPressSite::factory()->create([
        'user_id' => $this->user->id,
        'mcp_enabled' => false,
    ]);

    $response = $this->post(route('wordpress.discoverCapabilities', $site));

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

test('discoverCapabilities discovers mcp tools', function () {
    Http::fake([
        '*/wp-json/mcp/v1/tools' => Http::response([
            ['name' => 'create_post'],
            ['name' => 'update_post'],
        ]),
    ]);

    $site = WordPressSite::factory()->create([
        'user_id' => $this->user->id,
        'mcp_enabled' => true,
    ]);

    $response = $this->post(route('wordpress.discoverCapabilities', $site));

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('posts endpoint returns site posts', function () {
    $site = WordPressSite::factory()->create(['user_id' => $this->user->id]);

    $response = $this->get(route('wordpress.posts', $site));

    $response->assertOk();
    $response->assertJson([]);
});

test('suggestions endpoint returns pending suggestions', function () {
    $site = WordPressSite::factory()->create(['user_id' => $this->user->id]);
    ContentSuggestion::factory()->count(2)->create([
        'wordpress_site_id' => $site->id,
        'status' => 'pending',
    ]);

    $response = $this->get(route('wordpress.suggestions', $site));

    $response->assertOk();
    $response->assertJsonCount(2);
});

test('can approve content suggestion', function () {
    $suggestion = ContentSuggestion::factory()->create([
        'status' => 'pending',
    ]);

    $response = $this->post(route('wordpress.approveSuggestion', $suggestion));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $suggestion->refresh();
    expect($suggestion->status)->toBe('approved');
    expect($suggestion->approved_by)->toBe($this->user->id);
});

test('can reject content suggestion', function () {
    $suggestion = ContentSuggestion::factory()->create([
        'status' => 'pending',
    ]);

    $response = $this->post(route('wordpress.rejectSuggestion', $suggestion));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $suggestion->refresh();
    expect($suggestion->status)->toBe('rejected');
});

test('publish requires approved suggestion', function () {
    $suggestion = ContentSuggestion::factory()->create([
        'status' => 'pending',
    ]);

    $response = $this->post(route('wordpress.publishSuggestion', $suggestion));

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

test('can publish approved suggestion', function () {
    Http::fake([
        '*/wp-json/wp/v2/posts' => Http::response(['id' => 123]),
    ]);

    $suggestion = ContentSuggestion::factory()->create([
        'status' => 'approved',
    ]);

    $response = $this->post(route('wordpress.publishSuggestion', $suggestion));

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $suggestion->refresh();
    expect($suggestion->status)->toBe('published');
});

test('wordpress requires authentication', function () {
    auth()->logout();

    $response = $this->post(route('wordpress.store'), []);

    $response->assertRedirect(route('login'));
});
