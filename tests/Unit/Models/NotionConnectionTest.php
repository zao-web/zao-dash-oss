<?php

use App\Models\NotionConnection;

uses()->group('models');

test('has guarded attributes empty', function () {
    expect((new NotionConnection)->getGuarded())->toBe([]);
});

test('casts connected_at to datetime', function () {
    $connection = NotionConnection::factory()->create([
        'connected_at' => now(),
    ]);

    expect($connection->connected_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('hides access_token attribute', function () {
    expect((new NotionConnection)->getHidden())->toContain('access_token');
});

test('belongs to user relationship', function () {
    $connection = NotionConnection::factory()->create();

    expect($connection->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many pages relationship', function () {
    $connection = NotionConnection::factory()->create();

    expect($connection->pages())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('databases relationship filters by is_database', function () {
    $connection = NotionConnection::factory()->create();

    expect($connection->databases())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('encrypts access_token on set', function () {
    $connection = new NotionConnection;
    $connection->access_token = 'test-notion-token';

    expect($connection->getAttributes()['access_token'])->not->toBe('test-notion-token');
});

test('decrypts access_token on get', function () {
    $connection = NotionConnection::factory()->create();
    $connection->access_token = 'test-notion-token';
    $connection->save();

    $retrieved = NotionConnection::find($connection->id);
    expect($retrieved->access_token)->toBe('test-notion-token');
});

test('handles null access_token on get', function () {
    $connection = new NotionConnection;
    $connection->setAttribute('access_token', null);

    expect($connection->access_token)->toBeNull();
});

test('can be created via factory', function () {
    $connection = NotionConnection::factory()->create();

    expect($connection)->toBeInstanceOf(NotionConnection::class)
        ->and($connection->exists)->toBeTrue();
});
