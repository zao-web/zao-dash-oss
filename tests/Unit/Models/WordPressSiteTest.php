<?php

use App\Models\WordPressSite;

uses()->group('models');

test('has guarded attributes empty', function () {
    expect((new WordPressSite)->getGuarded())->toBe([]);
});

test('casts mcp_enabled to boolean', function () {
    $site = WordPressSite::factory()->create(['mcp_enabled' => true]);

    expect($site->mcp_enabled)->toBeTrue();
});

test('casts is_primary to boolean', function () {
    $site = WordPressSite::factory()->create(['is_primary' => true]);

    expect($site->is_primary)->toBeTrue();
});

test('casts capabilities to array', function () {
    $site = WordPressSite::factory()->create([
        'capabilities' => ['posts', 'pages', 'media'],
    ]);

    expect($site->capabilities)->toBeArray()
        ->and($site->capabilities)->toBe(['posts', 'pages', 'media']);
});

test('casts last_connected_at to datetime', function () {
    $site = WordPressSite::factory()->create([
        'last_connected_at' => now(),
    ]);

    expect($site->last_connected_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('hides application_password attribute', function () {
    expect((new WordPressSite)->getHidden())->toContain('application_password');
});

test('belongs to client relationship', function () {
    $site = WordPressSite::factory()->create();

    expect($site->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many posts relationship', function () {
    $site = WordPressSite::factory()->create();

    expect($site->posts())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many contentSuggestions relationship', function () {
    $site = WordPressSite::factory()->create();

    expect($site->contentSuggestions())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('encrypts application_password on set', function () {
    $site = new WordPressSite;
    $site->application_password = 'test-app-password';

    expect($site->getAttributes()['application_password'])->not->toBe('test-app-password');
});

test('decrypts application_password on get', function () {
    $site = WordPressSite::factory()->create();
    $site->application_password = 'test-app-password';
    $site->save();

    $retrieved = WordPressSite::find($site->id);
    expect($retrieved->application_password)->toBe('test-app-password');
});

test('handles null application_password on get', function () {
    $site = new WordPressSite;
    $site->setAttribute('application_password', null);

    expect($site->application_password)->toBeNull();
});

test('getMcpEndpointAttribute returns rest_url if set', function () {
    $site = WordPressSite::factory()->create([
        'rest_url' => 'https://example.com/custom-wp-json',
        'url' => 'https://example.com',
    ]);

    expect($site->mcp_endpoint)->toBe('https://example.com/custom-wp-json');
});

test('getMcpEndpointAttribute generates default endpoint from url', function () {
    $site = WordPressSite::factory()->create([
        'rest_url' => null,
        'url' => 'https://example.com/',
    ]);

    expect($site->mcp_endpoint)->toBe('https://example.com/wp-json/mcp/mcp-adapter-default-server');
});

test('getMcpEndpointAttribute handles url without trailing slash', function () {
    $site = WordPressSite::factory()->create([
        'rest_url' => null,
        'url' => 'https://example.com',
    ]);

    expect($site->mcp_endpoint)->toBe('https://example.com/wp-json/mcp/mcp-adapter-default-server');
});

test('getAuthHeaderAttribute returns basic auth header', function () {
    $site = WordPressSite::factory()->create([
        'username' => 'testuser',
    ]);
    $site->application_password = 'testpass';
    $site->save();

    $expected = 'Basic '.base64_encode('testuser:testpass');
    expect($site->fresh()->auth_header)->toBe($expected);
});

test('primary scope filters by is_primary', function () {
    $primary = WordPressSite::factory()->create(['is_primary' => true]);
    $secondary = WordPressSite::factory()->create(['is_primary' => false]);

    $results = WordPressSite::primary()->get();

    expect($results->contains($primary))->toBeTrue()
        ->and($results->contains($secondary))->toBeFalse();
});

test('can be created via factory', function () {
    $site = WordPressSite::factory()->create();

    expect($site)->toBeInstanceOf(WordPressSite::class)
        ->and($site->exists)->toBeTrue();
});
