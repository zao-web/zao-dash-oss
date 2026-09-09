<?php

use App\Models\SlackWorkspace;

test('has guarded attributes empty', function () {
    expect((new SlackWorkspace)->getGuarded())->toBe(['*']);
});

test('casts is_primary to boolean', function () {
    $workspace = SlackWorkspace::factory()->create(['is_primary' => true]);

    expect($workspace->is_primary)->toBeTrue();
});

test('hides access_token attribute', function () {
    expect((new SlackWorkspace)->getHidden())->toContain('access_token');
});

test('has many channels relationship', function () {
    $workspace = SlackWorkspace::factory()->create();

    expect($workspace->channels())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many messages relationship', function () {
    $workspace = SlackWorkspace::factory()->create();

    expect($workspace->messages())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('encrypts access_token on set', function () {
    $workspace = new SlackWorkspace;
    $workspace->access_token = 'test-token';

    expect($workspace->getAttributes()['access_token'])->not->toBe('test-token');
});

test('decrypts access_token on get', function () {
    $workspace = SlackWorkspace::factory()->create();
    $workspace->access_token = 'test-token';
    $workspace->save();

    $retrieved = SlackWorkspace::find($workspace->id);
    expect($retrieved->access_token)->toBe('test-token');
});

test('monitoredChannels relationship filters by monitoring_enabled', function () {
    $workspace = SlackWorkspace::factory()->create();

    expect($workspace->monitoredChannels())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('clientChannels relationship filters by classification', function () {
    $workspace = SlackWorkspace::factory()->create();

    expect($workspace->clientChannels())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('can be created via factory', function () {
    $workspace = SlackWorkspace::factory()->create();

    expect($workspace)->toBeInstanceOf(SlackWorkspace::class)
        ->and($workspace->exists)->toBeTrue();
});
