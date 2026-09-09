<?php

use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Services\Reports\RetainerNarrativeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('services.cloudflare.account_id', 'acct_test');
    config()->set('services.cloudflare.api_token', 'token_test');
    config()->set('services.cloudflare.narrative_model', '@cf/test/model');

    $client = Client::factory()->create(['name' => 'Example Client LLC']);
    $this->period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);

    $this->validJson = json_encode([
        'topics' => [[
            'title' => 'Work', 'summary' => 's', 'estimated_hours' => 4.0,
            'status' => 'completed', 'start_date' => '2026-05-10', 'end_date' => '2026-05-10', 'evidence' => [],
        ]],
        'value_summary' => 'Delivered.',
    ]);
});

it('retries without response_format when the structured call returns HTTP 400', function () {
    // First call (with response_format) 400s — as Workers AI models that don't
    // support JSON mode do. Second call (without it) succeeds.
    Http::fakeSequence('api.cloudflare.com/*')
        ->push(['success' => false, 'errors' => [['message' => 'response_format not supported']]], 400)
        ->push(['success' => true, 'result' => ['response' => $this->validJson]], 200);

    $result = app(RetainerNarrativeService::class)
        ->buildNarrativeFromText($this->period, 'transcript', persist: false);

    expect($result['warnings'])->toBe([])
        ->and($result['topics'])->toHaveCount(1)
        ->and($result['total_estimated_hours'])->toBe(4.0);

    Http::assertSentCount(2);
});

it('uses the structured call result when it succeeds (no retry)', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['response' => $this->validJson]], 200),
    ]);

    $result = app(RetainerNarrativeService::class)
        ->buildNarrativeFromText($this->period, 'transcript', persist: false);

    expect($result['warnings'])->toBe([])
        ->and($result['topics'])->toHaveCount(1);

    Http::assertSentCount(1);
});

it('warns when both structured and unstructured calls fail', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => false], 400),
    ]);

    $result = app(RetainerNarrativeService::class)
        ->buildNarrativeFromText($this->period, 'transcript', persist: false);

    expect($result['warnings'])->not->toBe([]);
    Http::assertSentCount(2);
});
