<?php

use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates the current-month period on demand and redirects to it', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 5000,
        'default_hourly_rate' => 250,
    ]);

    // Only a stale prior-month period exists.
    $may = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => now()->subMonth()->startOfMonth(),
        'period_end' => now()->subMonth()->endOfMonth(),
        'status' => 'active',
    ]);

    $response = $this->actingAs($owner)->get("/retainers/{$may->id}/current");

    // Exactly one active period now — the freshly-created current month.
    $current = RetainerPeriod::where('client_id', $client->id)
        ->where('status', 'active')
        ->sole();

    $response->assertRedirect(route('retainers.show', $current));
    expect($current->period_start->toDateString())->toBe(now()->startOfMonth()->toDateString());
    expect($may->fresh()->status)->toBe('closed');
});

it('reuses the current period if it already exists', function () {
    $owner = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 5000,
    ]);

    $current = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'status' => 'active',
    ]);

    $this->actingAs($owner)->get("/retainers/{$current->id}/current")
        ->assertRedirect(route('retainers.show', $current));

    expect(RetainerPeriod::where('client_id', $client->id)->count())->toBe(1);
});
