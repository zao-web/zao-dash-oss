<?php

use App\Models\Client;
use App\Models\ProfitabilitySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new ProfitabilitySnapshot)->getGuarded())->toBe([]);
});

test('casts period_start to date', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create(['period_start' => '2025-01-01']);

    expect($snapshot->period_start)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts period_end to date', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create(['period_end' => '2025-01-31']);

    expect($snapshot->period_end)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('casts hours_logged to decimal', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create(['hours_logged' => 160.50]);

    expect($snapshot->hours_logged)->toBeFloat();
});

test('casts hours_billable to decimal', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create(['hours_billable' => 140.25]);

    expect($snapshot->hours_billable)->toBeFloat();
});

test('casts revenue to decimal', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create(['revenue' => 15000.00]);

    expect($snapshot->revenue)->toBeFloat();
});

test('casts cost to decimal', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create(['cost' => 10000.00]);

    expect($snapshot->cost)->toBeFloat();
});

test('casts profit to decimal', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create(['profit' => 5000.00]);

    expect($snapshot->profit)->toBeFloat();
});

test('casts margin_percent to decimal', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create(['margin_percent' => 33.33]);

    expect($snapshot->margin_percent)->toBeFloat();
});

test('belongs to client relationship', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create();

    expect($snapshot->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to project relationship', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create();

    expect($snapshot->project())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to user relationship', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create();

    expect($snapshot->user())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('billable_ratio accessor calculates correct percentage', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create([
        'hours_logged' => 100.00,
        'hours_billable' => 80.00,
    ]);

    expect($snapshot->billable_ratio)->toBe(80.0);
});

test('billable_ratio accessor returns zero when hours_logged is zero', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create([
        'hours_logged' => 0,
        'hours_billable' => 0,
    ]);

    expect($snapshot->billable_ratio)->toBe(0.0);
});

test('effective_rate accessor calculates correct rate', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create([
        'revenue' => 10000.00,
        'hours_billable' => 100.00,
    ]);

    expect($snapshot->effective_rate)->toBe(100.0);
});

test('effective_rate accessor returns null when hours_billable is zero', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create([
        'revenue' => 10000.00,
        'hours_billable' => 0,
    ]);

    expect($snapshot->effective_rate)->toBeNull();
});

test('createForPeriod static method creates snapshot with correct data', function () {
    $client = Client::factory()->create();

    $snapshot = ProfitabilitySnapshot::createForPeriod(
        'monthly',
        '2025-01-01',
        '2025-01-31',
        [
            'client_id' => $client->id,
            'revenue' => 5000.00,
            'cost' => 3000.00,
            'profit' => 2000.00,
            'hours_logged' => 50.00,
            'hours_billable' => 40.00,
            'margin_percent' => 40.00,
        ]
    );

    expect($snapshot)->toBeInstanceOf(ProfitabilitySnapshot::class)
        ->and($snapshot->exists)->toBeTrue()
        ->and($snapshot->period_type)->toBe('monthly')
        ->and($snapshot->period_start->format('Y-m-d'))->toBe('2025-01-01')
        ->and($snapshot->period_end->format('Y-m-d'))->toBe('2025-01-31')
        ->and($snapshot->revenue)->toBe(5000.00)
        ->and($snapshot->client_id)->toBe($client->id);
});

test('can be created via factory', function () {
    $snapshot = ProfitabilitySnapshot::factory()->create();

    expect($snapshot)->toBeInstanceOf(ProfitabilitySnapshot::class)
        ->and($snapshot->exists)->toBeTrue();
});
