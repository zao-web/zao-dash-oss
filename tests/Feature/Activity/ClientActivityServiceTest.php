<?php

use App\Models\Client;
use App\Models\SlackMessage;
use App\Services\Activity\ClientActivityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config()->set('services.cloudflare.account_id', 'fake-account');
    config()->set('services.cloudflare.api_token', 'fake-token');
    config()->set('services.anthropic.api_key', null);
});

it('returns empty response when there is no activity', function () {
    $client = Client::factory()->create();

    $result = app(ClientActivityService::class)->synthesize($client);

    expect($result['items'])->toBeEmpty()
        ->and($result['warnings'])->toContain('No activity observed in the last 60 days.');
});

it('synthesises items from raw signals via the LLM', function () {
    $client = Client::factory()->create();
    SlackMessage::factory()->fromExternal()->create([
        'client_id' => $client->id,
        'sent_at' => now()->subDays(3),
        'content' => 'Can you fix the vendor registration error?',
    ]);

    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => ['response' => json_encode([
                'items' => [[
                    'title' => 'Vendor registration error',
                    'client_summary' => "You hit a registration error on May 3; we're on it.",
                    'status' => 'in_progress',
                    'first_raised_at' => '2026-05-03',
                    'last_activity_at' => '2026-05-12',
                    'evidence' => ['Slack: registration error report'],
                    'external_ids' => [
                        ['source' => 'slack', 'id' => 'C0123:1715000000.123456'],
                    ],
                ]],
            ])],
        ]),
    ]);

    $result = app(ClientActivityService::class)->synthesize($client, force: true);

    expect($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['title'])->toBe('Vendor registration error')
        ->and($result['items'][0]['status'])->toBe('in_progress')
        ->and($result['warnings'])->toBeEmpty();
});

it('drops items the LLM marks as noise', function () {
    $client = Client::factory()->create();
    SlackMessage::factory()->fromExternal()->create([
        'client_id' => $client->id,
        'sent_at' => now()->subDays(1),
        'content' => 'thanks!',
    ]);

    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => ['response' => json_encode([
                'items' => [[
                    'title' => 'Just an ack',
                    'client_summary' => 'noise',
                    'status' => 'noise',
                    'evidence' => ['Slack: thanks'],
                    'external_ids' => [['source' => 'slack', 'id' => 'C0123:noise']],
                ]],
            ])],
        ]),
    ]);

    $result = app(ClientActivityService::class)->synthesize($client, force: true);

    expect($result['items'])->toBeEmpty();
});

it('drops items missing external_ids since they cannot be deduped', function () {
    $client = Client::factory()->create();
    SlackMessage::factory()->fromExternal()->create([
        'client_id' => $client->id,
        'sent_at' => now()->subDays(1),
        'content' => 'real ask',
    ]);

    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => ['response' => json_encode([
                'items' => [[
                    'title' => 'Phantom item',
                    'client_summary' => '...',
                    'status' => 'in_progress',
                    'evidence' => ['Slack: vague'],
                    'external_ids' => [],
                ]],
            ])],
        ]),
    ]);

    $result = app(ClientActivityService::class)->synthesize($client, force: true);

    expect($result['items'])->toBeEmpty();
});
