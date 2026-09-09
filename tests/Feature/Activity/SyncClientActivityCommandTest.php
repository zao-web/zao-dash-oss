<?php

use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Models\SlackMessage;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config()->set('services.cloudflare.account_id', 'fake-account');
    config()->set('services.cloudflare.api_token', 'fake-token');
});

it('walks active retainer clients and persists synthesised tasks', function () {
    $client = Client::factory()->create();
    RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'status' => 'active',
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);
    SlackMessage::factory()->fromExternal()->create([
        'client_id' => $client->id,
        'sent_at' => now()->subDays(2),
        'content' => 'Please help with the vendor registration error.',
    ]);

    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => ['response' => json_encode([
                'items' => [[
                    'title' => 'Vendor registration error',
                    'client_summary' => "You hit a registration error; we're on it.",
                    'status' => 'in_progress',
                    'first_raised_at' => now()->subDays(2)->toDateString(),
                    'last_activity_at' => now()->subDays(2)->toDateString(),
                    'evidence' => ['Slack: registration error'],
                    'external_ids' => [['source' => 'slack', 'id' => 'C0123:fake']],
                ]],
            ])],
        ]),
    ]);

    $this->artisan('activity:sync')->assertExitCode(0);

    expect(Task::where('source', 'activity-feed')->count())->toBe(1);
});

it('honours the --client filter', function () {
    $a = Client::factory()->create(['slug' => 'client-a']);
    $b = Client::factory()->create(['slug' => 'client-b']);
    foreach ([$a, $b] as $client) {
        RetainerPeriod::factory()->create([
            'client_id' => $client->id,
            'status' => 'active',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
        ]);
        SlackMessage::factory()->fromExternal()->create([
            'client_id' => $client->id,
            'sent_at' => now()->subDay(),
        ]);
    }

    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => ['response' => json_encode(['items' => []])],
        ]),
    ]);

    $this->artisan('activity:sync', ['--client' => 'client-a'])
        ->expectsOutputToContain($a->name)
        ->doesntExpectOutputToContain($b->name)
        ->assertExitCode(0);
});
