<?php

use App\Models\CommandHistory;

test('table name is command_history', function () {
    expect((new CommandHistory)->getTable())->toBe('command_history');
});

test('has guarded attributes empty', function () {
    expect((new CommandHistory)->getGuarded())->toBe(['*']);
});

test('casts metadata to array', function () {
    $history = CommandHistory::factory()->create(['metadata' => ['key' => 'value']]);

    expect($history->metadata)->toBeArray()
        ->and($history->metadata)->toBe(['key' => 'value']);
});

test('casts executed_at to datetime', function () {
    $history = CommandHistory::factory()->create(['executed_at' => now()]);

    expect($history->executed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to user relationship', function () {
    $history = CommandHistory::factory()->create();

    expect($history->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('can be created via factory', function () {
    $history = CommandHistory::factory()->create();

    expect($history)->toBeInstanceOf(CommandHistory::class)
        ->and($history->exists)->toBeTrue();
});
