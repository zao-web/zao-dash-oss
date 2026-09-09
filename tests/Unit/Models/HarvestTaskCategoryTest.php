<?php

use App\Models\Client;
use App\Models\HarvestTaskCategory;
use App\Models\TimeEntry;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new HarvestTaskCategory)->getGuarded())->toBe(['*']);
});

test('casts is_default to boolean', function () {
    $category = HarvestTaskCategory::create([
        'harvest_id' => '12345',
        'name' => 'Development',
        'is_default' => true,
        'is_active' => true,
    ]);

    expect($category->is_default)->toBeTrue();
});

test('casts is_active to boolean', function () {
    $category = HarvestTaskCategory::create([
        'harvest_id' => '12345',
        'name' => 'Development',
        'is_default' => false,
        'is_active' => false,
    ]);

    expect($category->is_active)->toBeFalse();
});

test('casts default_hourly_rate to decimal', function () {
    $category = HarvestTaskCategory::create([
        'harvest_id' => '12345',
        'name' => 'Development',
        'default_hourly_rate' => 150.50,
        'is_active' => true,
    ]);

    expect($category->default_hourly_rate)->toBeFloat()
        ->and((string) $category->default_hourly_rate)->toBe('150.50');
});

test('has many time entries relationship', function () {
    $category = HarvestTaskCategory::create([
        'harvest_id' => '12345',
        'name' => 'Development',
        'is_active' => true,
    ]);

    expect($category->timeEntries())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('getTotalHoursAttribute sums time entries hours', function () {
    $user = User::factory()->create();
    $client = Client::factory()->create();

    $category = HarvestTaskCategory::create([
        'harvest_id' => '12345',
        'name' => 'Development',
        'is_active' => true,
    ]);

    TimeEntry::create([
        'harvest_id' => 'entry-1',
        'user_id' => $user->id,
        'client_id' => $client->id,
        'harvest_task_id' => '12345',
        'hours' => 5.5,
        'spent_date' => today(),
        'is_running' => false,
        'is_billable' => true,
        'is_billed' => false,
    ]);

    TimeEntry::create([
        'harvest_id' => 'entry-2',
        'user_id' => $user->id,
        'client_id' => $client->id,
        'harvest_task_id' => '12345',
        'hours' => 3.25,
        'spent_date' => today(),
        'is_running' => false,
        'is_billable' => true,
        'is_billed' => false,
    ]);

    expect($category->total_hours)->toBe(8.75);
});

test('getTotalHoursAttribute returns zero when no time entries', function () {
    $category = HarvestTaskCategory::create([
        'harvest_id' => '12345',
        'name' => 'Development',
        'is_active' => true,
    ]);

    expect($category->total_hours)->toBe(0.0);
});

test('can be created', function () {
    $category = HarvestTaskCategory::create([
        'harvest_id' => '12345',
        'name' => 'Development',
        'is_active' => true,
    ]);

    expect($category)->toBeInstanceOf(HarvestTaskCategory::class)
        ->and($category->exists)->toBeTrue();
});
