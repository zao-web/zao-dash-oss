<?php

use App\Models\Client;
use App\Models\RetainerPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function recurringClient(): Client
{
    return Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 5000,
        'default_hourly_rate' => 250,
    ]);
}

it('creates the current-month active period for a recurring client', function () {
    $client = recurringClient();

    $this->artisan('retainers:sync')->assertSuccessful();

    $period = RetainerPeriod::where('client_id', $client->id)->sole();
    expect($period->status)->toBe('active');
    expect($period->period_start->toDateString())->toBe(now()->startOfMonth()->toDateString());
    expect($period->period_end->toDateString())->toBe(now()->endOfMonth()->toDateString());
});

it('closes a stale active period from a prior month when rolling over', function () {
    $client = recurringClient();

    // Last month's period left active because the command never ran on the 1st.
    $stale = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => now()->subMonth()->startOfMonth(),
        'period_end' => now()->subMonth()->endOfMonth(),
        'status' => 'active',
    ]);

    $this->artisan('retainers:sync')->assertSuccessful();

    expect($stale->fresh()->status)->toBe('closed');
});

it('leaves exactly one active period per client after running', function () {
    $client = recurringClient();

    RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => now()->subMonth()->startOfMonth(),
        'period_end' => now()->subMonth()->endOfMonth(),
        'status' => 'active',
    ]);

    $this->artisan('retainers:sync')->assertSuccessful();

    $active = RetainerPeriod::where('client_id', $client->id)->where('status', 'active')->get();
    expect($active)->toHaveCount(1);
    expect($active->first()->period_start->toDateString())->toBe(now()->startOfMonth()->toDateString());
});

it('is idempotent — a second run does not create a duplicate period', function () {
    $client = recurringClient();

    $this->artisan('retainers:sync')->assertSuccessful();
    $this->artisan('retainers:sync')->assertSuccessful();

    expect(RetainerPeriod::where('client_id', $client->id)->count())->toBe(1);
});

it('ignores clients without recurring invoices enabled', function () {
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => false,
        'recurring_invoice_amount' => 0,
    ]);

    $this->artisan('retainers:sync')->assertSuccessful();

    expect(RetainerPeriod::where('client_id', $client->id)->count())->toBe(0);
});
