<?php

use App\Agents\Tools\WpCreatePostTool;
use App\Models\WordPressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('handshakes rest auth on dry run without creating a post', function () {
    $site = WordPressSite::factory()->create([
        'name' => 'Zao.is',
        'url' => 'https://example.com',
        'rest_url' => null,
        'username' => 'justin',
        'application_password' => 'dash-stored-password',
        'mcp_enabled' => true,
        'is_primary' => true,
    ]);

    Http::fake([
        'https://example.com/wp-json/wp/v2/users/me' => Http::response([
            'id' => 7,
            'name' => 'justin',
        ], 200),
        'https://example.com/wp-json*' => Http::response([
            'namespaces' => ['wp/v2'],
        ], 200),
    ]);

    $result = (new WpCreatePostTool)->execute([
        'dry_run' => true,
    ]);

    expect($result)
        ->toMatchArray([
            'success' => true,
            'dry_run' => true,
            'published' => false,
            'rest_url' => 'https://example.com/wp-json',
            'authenticated_user' => 'justin',
        ]);

    expect($site->fresh()->rest_url)->toBe('https://example.com/wp-json');

    Http::assertNotSent(fn ($request) => $request->method() === 'POST'
        && str_contains($request->url(), '/wp-json/wp/v2/posts'));
});

it('does not require title or content in the agent input schema', function () {
    expect((new WpCreatePostTool)->inputSchema()['required'] ?? [])
        ->not->toContain('title')
        ->not->toContain('content');
});
