<?php

use App\Models\ClientReport;

test('has guarded attributes empty', function () {
    expect((new ClientReport)->getGuarded())->toBe(['*']);
});

test('casts period_start to date', function () {
    $report = ClientReport::factory()->create(['period_start' => '2025-01-01']);

    expect($report->period_start)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts period_end to date', function () {
    $report = ClientReport::factory()->create(['period_end' => '2025-01-31']);

    expect($report->period_end)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts data_snapshot to array', function () {
    $report = ClientReport::factory()->create(['data_snapshot' => ['hours' => 100, 'revenue' => 5000]]);

    expect($report->data_snapshot)->toBeArray();
});

test('casts sent_at to datetime', function () {
    $report = ClientReport::factory()->create(['sent_at' => now()]);

    expect($report->sent_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts sent_to to array', function () {
    $report = ClientReport::factory()->create(['sent_to' => ['email1@test.com', 'email2@test.com']]);

    expect($report->sent_to)->toBeArray();
});

test('casts opened_at to datetime', function () {
    $report = ClientReport::factory()->create(['opened_at' => now()]);

    expect($report->opened_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to client relationship', function () {
    $report = ClientReport::factory()->create();

    expect($report->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('isSent returns true when sent_at is not null', function () {
    $report = ClientReport::factory()->create(['sent_at' => now()]);

    expect($report->isSent())->toBeTrue();
});

test('isSent returns false when sent_at is null', function () {
    $report = ClientReport::factory()->create(['sent_at' => null]);

    expect($report->isSent())->toBeFalse();
});

test('isOpened returns true when opened_at is not null', function () {
    $report = ClientReport::factory()->create(['opened_at' => now()]);

    expect($report->isOpened())->toBeTrue();
});

test('isOpened returns false when opened_at is null', function () {
    $report = ClientReport::factory()->create(['opened_at' => null]);

    expect($report->isOpened())->toBeFalse();
});

test('can be created via factory', function () {
    $report = ClientReport::factory()->create();

    expect($report)->toBeInstanceOf(ClientReport::class)
        ->and($report->exists)->toBeTrue();
});
