<?php

use App\Models\Client;
use App\Models\QboCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has guarded attributes empty', function () {
    expect((new QboCustomer)->getGuarded())->toBe([]);
});

test('casts balance to decimal', function () {
    $customer = QboCustomer::factory()->create(['balance' => 1500.75]);

    expect($customer->balance)->toBeFloat();
});

test('casts active to boolean', function () {
    $customer = QboCustomer::factory()->create(['active' => true]);

    expect($customer->active)->toBeBool()
        ->and($customer->active)->toBeTrue();
});

test('casts synced_at to datetime', function () {
    $customer = QboCustomer::factory()->create(['synced_at' => now()]);

    expect($customer->synced_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('belongs to quickbooks connection relationship', function () {
    $customer = QboCustomer::factory()->create();

    expect($customer->connection())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to client relationship', function () {
    $customer = QboCustomer::factory()->create();

    expect($customer->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('active scope filters active customers', function () {
    QboCustomer::factory()->create(['active' => true, 'display_name' => 'Active Customer']);
    QboCustomer::factory()->create(['active' => false, 'display_name' => 'Inactive Customer']);

    $activeCustomers = QboCustomer::active()->get();

    expect($activeCustomers)->toHaveCount(1)
        ->and($activeCustomers->first()->display_name)->toBe('Active Customer');
});

test('withBalance scope filters customers with balance', function () {
    QboCustomer::factory()->create(['balance' => 100.00]);
    QboCustomer::factory()->create(['balance' => 0.00]);
    QboCustomer::factory()->create(['balance' => -50.00]);

    $customersWithBalance = QboCustomer::withBalance()->get();

    expect($customersWithBalance)->toHaveCount(1)
        ->and($customersWithBalance->first()->balance)->toBeGreaterThan(0);
});

test('unmatched scope filters customers without client', function () {
    $client = Client::factory()->create();
    QboCustomer::factory()->create(['client_id' => null, 'display_name' => 'Unmatched']);
    QboCustomer::factory()->create(['client_id' => $client->id, 'display_name' => 'Matched']);

    $unmatchedCustomers = QboCustomer::unmatched()->get();

    expect($unmatchedCustomers)->toHaveCount(1)
        ->and($unmatchedCustomers->first()->display_name)->toBe('Unmatched')
        ->and($unmatchedCustomers->first()->client_id)->toBeNull();
});

test('matchToClient updates client_id', function () {
    $client = Client::factory()->create();
    $customer = QboCustomer::factory()->create(['client_id' => null]);

    expect($customer->client_id)->toBeNull();

    $customer->matchToClient($client->id);

    expect($customer->fresh()->client_id)->toBe($client->id);
});

test('can be created via factory', function () {
    $customer = QboCustomer::factory()->create();

    expect($customer)->toBeInstanceOf(QboCustomer::class)
        ->and($customer->exists)->toBeTrue();
});
