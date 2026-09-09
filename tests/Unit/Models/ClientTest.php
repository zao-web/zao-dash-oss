<?php

use App\Models\Client;
use Illuminate\Database\Eloquent\SoftDeletes;

test('uses soft deletes', function () {
    expect(in_array(SoftDeletes::class, class_uses(Client::class)))->toBeTrue();
});

test('has guarded attributes empty', function () {
    expect((new Client)->getGuarded())->toBe(['*']);
});

test('casts health_score to decimal', function () {
    $client = Client::factory()->create(['health_score' => 85.5]);

    expect($client->health_score)->toBeFloat()
        ->and((string) $client->health_score)->toBe('85.5');
});

test('has many contacts relationship', function () {
    $client = Client::factory()->create();

    expect($client->contacts())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many projects relationship', function () {
    $client = Client::factory()->create();

    expect($client->projects())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many harvest projects relationship', function () {
    $client = Client::factory()->create();

    expect($client->harvestProjects())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many retainer periods relationship', function () {
    $client = Client::factory()->create();

    expect($client->retainerPeriods())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many client reports relationship', function () {
    $client = Client::factory()->create();

    expect($client->clientReports())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many invoices relationship', function () {
    $client = Client::factory()->create();

    expect($client->invoices())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many health alerts relationship', function () {
    $client = Client::factory()->create();

    expect($client->healthAlerts())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('unresolved health alerts relationship uses scope', function () {
    $client = Client::factory()->create();

    expect($client->unresolvedHealthAlerts())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('can be soft deleted', function () {
    $client = Client::factory()->create();
    $client->delete();

    expect($client->trashed())->toBeTrue()
        ->and(Client::withTrashed()->find($client->id))->not->toBeNull();
});

test('can be created via factory', function () {
    $client = Client::factory()->create();

    expect($client)->toBeInstanceOf(Client::class)
        ->and($client->exists)->toBeTrue();
});
