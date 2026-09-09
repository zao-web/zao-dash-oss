<?php

use App\Models\NotionDatabaseItem;

uses()->group('models');

test('has guarded attributes empty', function () {
    expect((new NotionDatabaseItem)->getGuarded())->toBe([]);
});

test('casts properties to array', function () {
    $item = NotionDatabaseItem::factory()->create([
        'properties' => ['key' => 'value', 'foo' => 'bar'],
    ]);

    expect($item->properties)->toBeArray()
        ->and($item->properties)->toBe(['key' => 'value', 'foo' => 'bar']);
});

test('casts due_date to date', function () {
    $item = NotionDatabaseItem::factory()->create([
        'due_date' => '2025-12-31',
    ]);

    expect($item->due_date)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('casts synced_at to datetime', function () {
    $item = NotionDatabaseItem::factory()->create([
        'synced_at' => now(),
    ]);

    expect($item->synced_at)->toBeInstanceOf(\Carbon\Carbon::class);
});

test('belongs to database relationship', function () {
    $item = NotionDatabaseItem::factory()->create();

    expect($item->database())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('withStatus scope filters by status', function () {
    $todo = NotionDatabaseItem::factory()->create(['status' => 'To Do']);
    $done = NotionDatabaseItem::factory()->create(['status' => 'Done']);

    $results = NotionDatabaseItem::withStatus('To Do')->get();

    expect($results->contains($todo))->toBeTrue()
        ->and($results->contains($done))->toBeFalse();
});

test('overdue scope filters by overdue items', function () {
    $overdue = NotionDatabaseItem::factory()->create([
        'due_date' => now()->subDay(),
        'status' => 'In Progress',
    ]);
    $upcoming = NotionDatabaseItem::factory()->create([
        'due_date' => now()->addDay(),
        'status' => 'In Progress',
    ]);
    $done = NotionDatabaseItem::factory()->create([
        'due_date' => now()->subDay(),
        'status' => 'Done',
    ]);

    $results = NotionDatabaseItem::overdue()->get();

    expect($results->contains($overdue))->toBeTrue()
        ->and($results->contains($upcoming))->toBeFalse()
        ->and($results->contains($done))->toBeFalse();
});

test('overdue scope excludes items without due_date', function () {
    $noDueDate = NotionDatabaseItem::factory()->create([
        'due_date' => null,
        'status' => 'In Progress',
    ]);

    $results = NotionDatabaseItem::overdue()->get();

    expect($results->contains($noDueDate))->toBeFalse();
});

test('upcoming scope filters items due within specified days', function () {
    $upcoming = NotionDatabaseItem::factory()->create([
        'due_date' => now()->addDays(3),
    ]);
    $farFuture = NotionDatabaseItem::factory()->create([
        'due_date' => now()->addDays(10),
    ]);
    $past = NotionDatabaseItem::factory()->create([
        'due_date' => now()->subDay(),
    ]);

    $results = NotionDatabaseItem::upcoming(7)->get();

    expect($results->contains($upcoming))->toBeTrue()
        ->and($results->contains($farFuture))->toBeFalse()
        ->and($results->contains($past))->toBeFalse();
});

test('upcoming scope defaults to 7 days', function () {
    $withinWeek = NotionDatabaseItem::factory()->create([
        'due_date' => now()->addDays(6),
    ]);
    $beyondWeek = NotionDatabaseItem::factory()->create([
        'due_date' => now()->addDays(8),
    ]);

    $results = NotionDatabaseItem::upcoming()->get();

    expect($results->contains($withinWeek))->toBeTrue()
        ->and($results->contains($beyondWeek))->toBeFalse();
});

test('upcoming scope excludes items without due_date', function () {
    $noDueDate = NotionDatabaseItem::factory()->create(['due_date' => null]);

    $results = NotionDatabaseItem::upcoming()->get();

    expect($results->contains($noDueDate))->toBeFalse();
});

test('can be created via factory', function () {
    $item = NotionDatabaseItem::factory()->create();

    expect($item)->toBeInstanceOf(NotionDatabaseItem::class)
        ->and($item->exists)->toBeTrue();
});
