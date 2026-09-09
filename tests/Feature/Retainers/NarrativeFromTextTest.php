<?php

use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Models\TimeEntry;
use App\Services\Reports\RetainerNarrativeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Fake the Cloudflare Workers AI endpoint (the service's first provider) so the
 * pipeline runs without a real LLM call. Returns the given topics as the
 * model's JSON response.
 */
function fakeLlm(array $topics, string $summary = 'Delivered meaningful work this period.'): void
{
    config()->set('services.cloudflare.account_id', 'acct_test');
    config()->set('services.cloudflare.api_token', 'token_test');

    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => [
                'response' => json_encode(['topics' => $topics, 'value_summary' => $summary]),
            ],
        ]),
    ]);
}

it('builds and persists a narrative from a transcript', function () {
    $client = Client::factory()->create(['name' => 'Example Client LLC']);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);

    fakeLlm([
        [
            'title' => 'Plugin updates and IP unblock',
            'summary' => 'Shipped plugin updates and resolved an IP block.',
            'estimated_hours' => 6.5,
            'status' => 'completed',
            'start_date' => '2026-05-21',
            'end_date' => '2026-05-23',
            'evidence' => ['Slack: discussed plugin bump'],
        ],
    ]);

    $result = app(RetainerNarrativeService::class)
        ->buildNarrativeFromText($period, 'cory: plugins updated. margie: tested. justin: shipped.');

    expect($result['warnings'])->toBe([])
        ->and($result['topics'])->toHaveCount(1)
        ->and($result['total_estimated_hours'])->toBe(6.5);

    // Persisted as ai_estimated entries the report reads.
    expect(TimeEntry::where('retainer_period_id', $period->id)->where('source', 'ai_estimated')->sum('hours'))
        ->toEqual(6.5);

    // Cached under the key the report's getCached() reads.
    expect(Cache::get("retainer.narrative.{$period->id}"))->not->toBeNull();
});

it('does not persist on a dry run', function () {
    $period = RetainerPeriod::factory()->create([
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);

    fakeLlm([[
        'title' => 'Some work', 'summary' => 'x', 'estimated_hours' => 3,
        'status' => 'completed', 'start_date' => '2026-05-10', 'end_date' => '2026-05-10', 'evidence' => [],
    ]]);

    app(RetainerNarrativeService::class)->buildNarrativeFromText($period, 'transcript', persist: false);

    expect(TimeEntry::where('retainer_period_id', $period->id)->count())->toBe(0)
        ->and(Cache::get("retainer.narrative.{$period->id}"))->toBeNull();
});

it('returns a warning for an empty transcript without calling the LLM', function () {
    Http::fake();
    $period = RetainerPeriod::factory()->create();

    $result = app(RetainerNarrativeService::class)->buildNarrativeFromText($period, '   ');

    expect($result['warnings'])->toContain('No transcript provided.');
    Http::assertNothingSent();
});

it('command persists from a transcript file and updates the report cache', function () {
    Storage::fake('local');
    Storage::put('locum-may.txt', 'cory: plugins. margie: tested. justin: shipped.');

    $client = Client::factory()->create(['name' => 'Example Client LLC']);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);

    fakeLlm([[
        'title' => 'Plugin updates', 'summary' => 'Shipped updates.', 'estimated_hours' => 4.0,
        'status' => 'completed', 'start_date' => '2026-05-21', 'end_date' => '2026-05-22', 'evidence' => [],
    ]]);

    $this->artisan('retainer:narrative-from-text', [
        '--period' => (string) $period->id,
        '--file' => 'locum-may.txt',
    ])->assertSuccessful();

    expect(TimeEntry::where('retainer_period_id', $period->id)->where('source', 'ai_estimated')->sum('hours'))
        ->toEqual(4.0);
});

it('command fails on a missing period', function () {
    $this->artisan('retainer:narrative-from-text', ['--period' => '999999'])
        ->assertFailed();
});

it('command fails when no period is given', function () {
    $this->artisan('retainer:narrative-from-text', ['--file' => 'x.txt'])
        ->assertFailed();
});
