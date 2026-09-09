<?php

use App\Models\TimeEntry;

test('has guarded attributes empty', function () {
    expect((new TimeEntry)->getGuarded())->toBe(['*']);
});

test('casts hours to decimal', function () {
    $entry = TimeEntry::factory()->create(['hours' => 5.50]);

    expect($entry->hours)->toBeFloat()
        ->and((string) $entry->hours)->toBe('5.50');
});

test('casts hourly_rate to decimal', function () {
    $entry = TimeEntry::factory()->create(['hourly_rate' => 150.00]);

    expect($entry->hourly_rate)->toBeFloat();
});

test('casts cost_rate to decimal', function () {
    $entry = TimeEntry::factory()->create(['cost_rate' => 75.00]);

    expect($entry->cost_rate)->toBeFloat();
});

test('casts spent_date to date', function () {
    $entry = TimeEntry::factory()->create(['spent_date' => '2025-12-13']);

    expect($entry->spent_date)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts is_running to boolean', function () {
    $entry = TimeEntry::factory()->create(['is_running' => true]);

    expect($entry->is_running)->toBeTrue();
});

test('casts is_billable to boolean', function () {
    $entry = TimeEntry::factory()->create(['is_billable' => false]);

    expect($entry->is_billable)->toBeFalse();
});

test('casts is_billed to boolean', function () {
    $entry = TimeEntry::factory()->create(['is_billed' => true]);

    expect($entry->is_billed)->toBeTrue();
});

test('casts timer_started_at to datetime', function () {
    $entry = TimeEntry::factory()->create(['timer_started_at' => now()]);

    expect($entry->timer_started_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to user relationship', function () {
    $entry = TimeEntry::factory()->create();

    expect($entry->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to client relationship', function () {
    $entry = TimeEntry::factory()->create();

    expect($entry->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to project relationship', function () {
    $entry = TimeEntry::factory()->create();

    expect($entry->project())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to task relationship', function () {
    $entry = TimeEntry::factory()->create();

    expect($entry->task())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('getBillableAmountAttribute calculates correctly', function () {
    $entry = TimeEntry::factory()->create(['hours' => 5.00, 'hourly_rate' => 100.00, 'is_billable' => true]);

    expect($entry->billable_amount)->toBe(500.0);
});

test('getBillableAmountAttribute returns zero when not billable', function () {
    $entry = TimeEntry::factory()->create(['hours' => 5.00, 'hourly_rate' => 100.00, 'is_billable' => false]);

    expect($entry->billable_amount)->toBe(0.0);
});

test('getBillableAmountAttribute returns zero when no hourly rate', function () {
    $entry = TimeEntry::factory()->create(['hours' => 5.00, 'hourly_rate' => null, 'is_billable' => true]);

    expect($entry->billable_amount)->toBe(0.0);
});

test('getCostAttribute calculates correctly', function () {
    $entry = TimeEntry::factory()->create(['hours' => 5.00, 'cost_rate' => 75.00]);

    expect($entry->cost)->toBe(375.0);
});

test('getCostAttribute returns zero when no cost rate', function () {
    $entry = TimeEntry::factory()->create(['hours' => 5.00, 'cost_rate' => null]);

    expect($entry->cost)->toBe(0.0);
});

test('isRunning returns correct value', function () {
    $running = TimeEntry::factory()->create(['is_running' => true]);
    $stopped = TimeEntry::factory()->create(['is_running' => false]);

    expect($running->isRunning())->toBeTrue()
        ->and($stopped->isRunning())->toBeFalse();
});

test('can be created via factory', function () {
    $entry = TimeEntry::factory()->create();

    expect($entry)->toBeInstanceOf(TimeEntry::class)
        ->and($entry->exists)->toBeTrue();
});
