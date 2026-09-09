<?php

use App\Models\NotionPage;

uses()->group('models');

test('has guarded attributes empty', function () {
    expect((new NotionPage)->getGuarded())->toBe([]);
});

test('casts properties_schema to array', function () {
    $page = NotionPage::factory()->create([
        'properties_schema' => ['name' => 'title', 'status' => 'select'],
    ]);

    expect($page->properties_schema)->toBeArray()
        ->and($page->properties_schema)->toBe(['name' => 'title', 'status' => 'select']);
});

test('casts is_database to boolean', function () {
    $page = NotionPage::factory()->create(['is_database' => true]);

    expect($page->is_database)->toBeTrue();
});

test('casts archived to boolean', function () {
    $page = NotionPage::factory()->create(['archived' => true]);

    expect($page->archived)->toBeTrue();
});

test('casts last_synced_at to datetime', function () {
    $page = NotionPage::factory()->create([
        'last_synced_at' => now(),
    ]);

    expect($page->last_synced_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('belongs to connection relationship', function () {
    $page = NotionPage::factory()->create();

    expect($page->connection())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to client relationship', function () {
    $page = NotionPage::factory()->create();

    expect($page->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has one content relationship', function () {
    $page = NotionPage::factory()->create();

    expect($page->content())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class);
});

test('has many items relationship', function () {
    $page = NotionPage::factory()->create();

    expect($page->items())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('belongs to parent relationship', function () {
    $page = NotionPage::factory()->create();

    expect($page->parent())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many children relationship', function () {
    $page = NotionPage::factory()->create();

    expect($page->children())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('databases scope filters is_database true', function () {
    $database = NotionPage::factory()->create(['is_database' => true]);
    $page = NotionPage::factory()->create(['is_database' => false]);

    $results = NotionPage::databases()->get();

    expect($results->contains($database))->toBeTrue()
        ->and($results->contains($page))->toBeFalse();
});

test('pages scope filters is_database false', function () {
    $database = NotionPage::factory()->create(['is_database' => true]);
    $page = NotionPage::factory()->create(['is_database' => false]);

    $results = NotionPage::pages()->get();

    expect($results->contains($page))->toBeTrue()
        ->and($results->contains($database))->toBeFalse();
});

test('active scope filters archived false', function () {
    $active = NotionPage::factory()->create(['archived' => false]);
    $archived = NotionPage::factory()->create(['archived' => true]);

    $results = NotionPage::active()->get();

    expect($results->contains($active))->toBeTrue()
        ->and($results->contains($archived))->toBeFalse();
});

test('can be created via factory', function () {
    $page = NotionPage::factory()->create();

    expect($page)->toBeInstanceOf(NotionPage::class)
        ->and($page->exists)->toBeTrue();
});
