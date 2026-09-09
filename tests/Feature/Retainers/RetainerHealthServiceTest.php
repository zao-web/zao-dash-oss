<?php

use App\Models\AgentRun;
use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Models\TimeEntry;
use App\Services\Reports\RetainerHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(RetainerHealthService::class);
    $this->client = Client::factory()->create();
    $this->retainer = RetainerPeriod::factory()->create([
        'client_id' => $this->client->id,
        'hours_included' => 20,
        'rollover_hours' => 0,
        'monthly_amount' => 5000.00,
        'internal_hourly_rate' => 250.00,
        'ai_equivalent_hourly_rate' => 50.00,
        'last_client_activity_at' => now()->subDays(5),
    ]);
    $this->periodStart = now()->startOfMonth();
    $this->periodEnd = now()->endOfMonth();
});

it('persists hours_used back to the retainer row so the index reflects fresh totals', function () {
    TimeEntry::factory()->create([
        'client_id' => $this->client->id,
        'spent_date' => now()->startOfMonth()->addDays(2),
        'hours' => 4.25,
        'effort_type' => 'development',
    ]);
    TimeEntry::factory()->create([
        'client_id' => $this->client->id,
        'spent_date' => now()->startOfMonth()->addDays(7),
        'hours' => 2.75,
        'effort_type' => 'meeting',
    ]);

    // Pre-condition: column starts at zero (or whatever Harvest left it at).
    $this->retainer->update(['hours_used' => 0]);

    $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: true
    );

    expect($this->retainer->fresh()->hours_used)->toEqual('7.00');
});

it('computes human hours from time entries in period correctly', function () {
    TimeEntry::factory()->create([
        'client_id' => $this->client->id,
        'spent_date' => now()->startOfMonth()->addDays(3),
        'hours' => 5.0,
        'effort_type' => 'development',
    ]);
    TimeEntry::factory()->create([
        'client_id' => $this->client->id,
        'spent_date' => now()->startOfMonth()->addDays(10),
        'hours' => 3.5,
        'effort_type' => 'meeting',
    ]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    expect($snapshot['human_hours'])->toBe(8.5);
});

it('computes agent cost from agent_runs filtered by client_id and period', function () {
    AgentRun::factory()->completed()->create([
        'client_id' => $this->client->id,
        'cost_usd' => 2.5000,
        'started_at' => now()->startOfMonth()->addDays(5),
    ]);
    AgentRun::factory()->completed()->create([
        'client_id' => $this->client->id,
        'cost_usd' => 1.2500,
        'started_at' => now()->startOfMonth()->addDays(10),
    ]);
    // Different client — should not count
    AgentRun::factory()->completed()->create([
        'client_id' => Client::factory(),
        'cost_usd' => 100.0000,
        'started_at' => now()->startOfMonth()->addDays(5),
    ]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    expect($snapshot['agent_cost_usd'])->toBe(3.75);
    expect($snapshot['agent_tasks_completed'])->toBe(2);
});

it('converts agent cost to equivalent hours using ai_equivalent_hourly_rate', function () {
    AgentRun::factory()->completed()->create([
        'client_id' => $this->client->id,
        'cost_usd' => 100.0000,
        'started_at' => now()->startOfMonth()->addDays(5),
    ]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    // 100 / 50 = 2.0 equivalent hours
    expect($snapshot['agent_equivalent_hours'])->toBe(2.0);
});

it('computes effective margin correctly when monthly_amount is set', function () {
    // 10 hours * $250 = $2500 human cost. No agent cost.
    TimeEntry::factory()->create([
        'client_id' => $this->client->id,
        'spent_date' => now()->startOfMonth()->addDays(3),
        'hours' => 10.0,
    ]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    // ($5000 - $2500) / $5000 * 100 = 50%
    expect($snapshot['effective_margin_percent'])->toBe(50.0);
});

it('returns null margin when monthly_amount is zero', function () {
    $this->retainer->update(['monthly_amount' => 0]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    expect($snapshot['effective_margin_percent'])->toBeNull();
});

it('sets health_status to critical when margin is negative', function () {
    // 30 hours * $250 = $7500 > $5000 monthly amount
    TimeEntry::factory()->create([
        'client_id' => $this->client->id,
        'spent_date' => now()->startOfMonth()->addDays(3),
        'hours' => 30.0,
    ]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    expect($snapshot['health_status'])->toBe('critical');
    expect($snapshot['effective_margin_percent'])->toBeLessThan(0);
});

it('sets health_status to warning when usage_percent exceeds 110', function () {
    // 25 hours out of 20 budget = 125% usage (with high margin to avoid critical)
    $this->retainer->update(['monthly_amount' => 50000.00]);
    TimeEntry::factory()->create([
        'client_id' => $this->client->id,
        'spent_date' => now()->startOfMonth()->addDays(3),
        'hours' => 25.0,
    ]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    expect($snapshot['usage_percent'])->toBeGreaterThan(110);
    expect($snapshot['health_status'])->toBe('warning');
});

it('sets health_status to warning when margin is below 20', function () {
    // 18 hours * $250 = $4500. ($5000 - $4500) / $5000 = 10%
    TimeEntry::factory()->create([
        'client_id' => $this->client->id,
        'spent_date' => now()->startOfMonth()->addDays(3),
        'hours' => 18.0,
    ]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    expect($snapshot['effective_margin_percent'])->toBeGreaterThanOrEqual(0);
    expect($snapshot['effective_margin_percent'])->toBeLessThan(20);
    expect($snapshot['health_status'])->toBe('warning');
});

it('sets health_status to silent when last_client_activity_at is null', function () {
    $this->retainer->update(['last_client_activity_at' => null]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    expect($snapshot['health_status'])->toBe('silent');
});

it('sets health_status to silent when last_client_activity_at exceeds 45 days', function () {
    $this->retainer->update(['last_client_activity_at' => now()->subDays(50)]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    expect($snapshot['health_status'])->toBe('silent');
});

it('silent overrides critical in health status priority', function () {
    $this->retainer->update(['last_client_activity_at' => null]);

    // Make it also critical (negative margin)
    TimeEntry::factory()->create([
        'client_id' => $this->client->id,
        'spent_date' => now()->startOfMonth()->addDays(3),
        'hours' => 30.0,
    ]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    // Silent takes priority over critical
    expect($snapshot['health_status'])->toBe('silent');
});

it('generates over_budget danger alert with correct hours in message', function () {
    TimeEntry::factory()->create([
        'client_id' => $this->client->id,
        'spent_date' => now()->startOfMonth()->addDays(3),
        'hours' => 25.0,
    ]);
    $this->retainer->update(['monthly_amount' => 50000.00]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    $dangerAlerts = collect($snapshot['alerts'])->where('type', 'danger');
    expect($dangerAlerts)->toHaveCount(1);
    expect($dangerAlerts->first()['message'])->toContain('Over budget by');
});

it('generates negative margin danger alert with dollar amount', function () {
    TimeEntry::factory()->create([
        'client_id' => $this->client->id,
        'spent_date' => now()->startOfMonth()->addDays(3),
        'hours' => 30.0,
    ]);

    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    $dangerAlerts = collect($snapshot['alerts'])->where('type', 'danger');
    $losingAlert = $dangerAlerts->first(fn ($a) => str_contains($a['message'], 'Losing'));
    expect($losingAlert)->not->toBeNull();
    expect($losingAlert['action'])->toContain('repricing');
});

it('generates no_meetings info alert when meeting_count is zero', function () {
    $snapshot = $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    $infoAlerts = collect($snapshot['alerts'])->where('type', 'info');
    $noMeetings = $infoAlerts->first(fn ($a) => str_contains($a['message'], 'No meetings'));
    expect($noMeetings)->not->toBeNull();
    expect($noMeetings['action'])->toContain('check-in');
});

it('persists snapshot fields to retainer_periods when persist is true', function () {
    AgentRun::factory()->completed()->create([
        'client_id' => $this->client->id,
        'cost_usd' => 5.0000,
        'started_at' => now()->startOfMonth()->addDays(5),
    ]);

    $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: true
    );

    $this->retainer->refresh();
    expect((float) $this->retainer->agent_cost_usd)->toBe(5.0);
    expect($this->retainer->agent_tasks_completed)->toBe(1);
    expect($this->retainer->health_status)->toBe('healthy');
});

it('does not update retainer_periods when persist is false', function () {
    AgentRun::factory()->completed()->create([
        'client_id' => $this->client->id,
        'cost_usd' => 5.0000,
        'started_at' => now()->startOfMonth()->addDays(5),
    ]);

    $this->service->computeAndPersistSnapshot(
        $this->retainer, $this->periodStart, $this->periodEnd, persist: false
    );

    $this->retainer->refresh();
    expect((float) $this->retainer->agent_cost_usd)->toBe(0.0);
    expect($this->retainer->agent_tasks_completed)->toBe(0);
});
