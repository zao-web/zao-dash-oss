<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\BuildRetainerNarrativeFromTextTool;
use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->adminUser = User::factory()->create(['role' => 'admin']);
    $this->client = Client::factory()->create(['name' => 'Example Client LLC']);
    $this->period = RetainerPeriod::factory()->create([
        'client_id' => $this->client->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);

    // Fake the Cloudflare provider so the pipeline runs without a real LLM.
    config()->set('services.cloudflare.account_id', 'acct_test');
    config()->set('services.cloudflare.api_token', 'token_test');
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => [
                'response' => json_encode([
                    'topics' => [[
                        'title' => 'Plugin updates and IP unblock',
                        'summary' => 'Shipped plugin updates and resolved an IP block.',
                        'estimated_hours' => 6.5,
                        'status' => 'completed',
                        'start_date' => '2026-05-21',
                        'end_date' => '2026-05-23',
                        'evidence' => ['Slack: plugin bump'],
                    ]],
                    'value_summary' => 'Kept the site healthy and shipped requested fixes.',
                ]),
            ],
        ]),
    ]);
});

test('persists a narrative from a pasted transcript', function () {
    $response = ZaoDashServer::actingAs($this->adminUser)->tool(BuildRetainerNarrativeFromTextTool::class, [
        'period_id' => $this->period->id,
        'transcript' => 'cory: plugins updated. margie: tested. justin: shipped.',
    ]);

    $response->assertOk();
    $response->assertSee('Plugin updates');

    expect(TimeEntry::where('retainer_period_id', $this->period->id)->where('source', 'ai_estimated')->sum('hours'))
        ->toEqual(6.5);
    expect(Cache::get("retainer.narrative.{$this->period->id}"))->not->toBeNull();
});

test('dry run does not persist', function () {
    $response = ZaoDashServer::actingAs($this->adminUser)->tool(BuildRetainerNarrativeFromTextTool::class, [
        'period_id' => $this->period->id,
        'transcript' => 'cory: plugins updated.',
        'dry_run' => true,
    ]);

    $response->assertOk();

    expect(TimeEntry::where('retainer_period_id', $this->period->id)->count())->toBe(0);
    expect(Cache::get("retainer.narrative.{$this->period->id}"))->toBeNull();
});

test('validates the period exists', function () {
    $response = ZaoDashServer::actingAs($this->adminUser)->tool(BuildRetainerNarrativeFromTextTool::class, [
        'period_id' => 999999,
        'transcript' => 'something',
    ]);

    $response->assertHasErrors();
});

test('requires a transcript', function () {
    $response = ZaoDashServer::actingAs($this->adminUser)->tool(BuildRetainerNarrativeFromTextTool::class, [
        'period_id' => $this->period->id,
    ]);

    $response->assertHasErrors();
});
