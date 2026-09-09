<?php

use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes all of the client\'s periods and disables recurring invoicing', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create([
        'recurring_invoice_enabled' => true,
        'recurring_invoice_amount' => 2500,
    ]);
    $current = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-08-01',
        'period_end' => '2026-08-31',
    ]);
    RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
    ]);

    $otherClient = Client::factory()->create();
    $otherPeriod = RetainerPeriod::factory()->create(['client_id' => $otherClient->id]);

    $this->actingAs($user)
        ->delete("/retainers/{$current->id}")
        ->assertRedirect(route('retainers.index'));

    expect(RetainerPeriod::where('client_id', $client->id)->count())->toBe(0);
    expect(RetainerPeriod::whereKey($otherPeriod->id)->exists())->toBeTrue();
    // Recurring invoicing off — the daily retainers:sync won't recreate a period.
    expect($client->fresh()->recurring_invoice_enabled)->toBeFalse();
});

it('deletes AI-estimated entries but keeps tracked time with a nulled period', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create(['recurring_invoice_enabled' => true, 'recurring_invoice_amount' => 1000]);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
    ]);

    $aiEntry = TimeEntry::create([
        'client_id' => $client->id, 'hours' => 2.0, 'source' => 'ai_estimated',
        'retainer_period_id' => $period->id, 'spent_date' => '2026-07-20', 'notes' => 'AI topic',
    ]);
    $manualEntry = TimeEntry::create([
        'client_id' => $client->id, 'hours' => 3.5, 'source' => 'manual',
        'retainer_period_id' => $period->id, 'spent_date' => '2026-07-10', 'notes' => 'Real tracked work',
    ]);

    $this->actingAs($user)->delete("/retainers/{$period->id}")->assertRedirect();

    expect(TimeEntry::whereKey($aiEntry->id)->exists())->toBeFalse();
    $manualEntry->refresh();
    expect($manualEntry->retainer_period_id)->toBeNull();
    expect((float) $manualEntry->hours)->toBe(3.5);
});

it('requires authentication', function () {
    $period = RetainerPeriod::factory()->create(['client_id' => Client::factory()->create()->id]);

    $this->delete("/retainers/{$period->id}")->assertRedirect('/login');
    expect(RetainerPeriod::whereKey($period->id)->exists())->toBeTrue();
});
