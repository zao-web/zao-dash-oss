<?php

use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->period = RetainerPeriod::factory()->create([
        'client_id' => $this->client->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);
    $this->entry = TimeEntry::create([
        'client_id' => $this->client->id,
        'retainer_period_id' => $this->period->id,
        'source' => 'ai_estimated',
        'hours' => 1.0,
        'spent_date' => '2026-05-15',
        'notes' => 'Update plugins',
        'is_billable' => true,
        'is_billed' => false,
    ]);
});

it('adjusts an entry and promotes it to manual', function () {
    $this->actingAs($this->user)
        ->post(route('retainers.report.entries.adjust', [$this->period, $this->entry]), [
            'hours' => 5.5,
            'notes' => 'Plugin updates across all sites',
        ])
        ->assertRedirect();

    $this->entry->refresh();
    expect((float) $this->entry->hours)->toBe(5.5)
        ->and($this->entry->source)->toBe('manual');
});

it('removes an entry', function () {
    $this->actingAs($this->user)
        ->delete(route('retainers.report.entries.delete', [$this->period, $this->entry]))
        ->assertRedirect();

    expect(TimeEntry::find($this->entry->id))->toBeNull();
});

it('refuses to edit an entry that belongs to a different period', function () {
    $other = RetainerPeriod::factory()->create(['client_id' => $this->client->id]);

    $this->actingAs($this->user)
        ->post(route('retainers.report.entries.adjust', [$other, $this->entry]), ['hours' => 9])
        ->assertNotFound();

    expect((float) $this->entry->fresh()->hours)->toBe(1.0);
});

it('does not let an unauthenticated request modify an entry', function () {
    $response = $this->post(route('retainers.report.entries.adjust', [$this->period, $this->entry]), ['hours' => 2]);

    // Guests are blocked (redirect to login, or 419 from CSRF) — either way
    // the entry must be untouched.
    expect($response->isSuccessful())->toBeFalse()
        ->and((float) $this->entry->fresh()->hours)->toBe(1.0);
});
