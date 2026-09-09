<?php

use App\Models\Client;
use App\Models\RetainerPeriod;
use App\Models\SlackChannel;
use App\Models\SlackMessage;
use App\Models\SlackWorkspace;
use App\Services\Reports\RetainerNarrativeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('cleanUtf8 makes malformed input safe for json_encode', function () {
    $svc = app(RetainerNarrativeService::class);
    $m = new ReflectionMethod($svc, 'cleanUtf8');
    $m->setAccessible(true);

    // Raw 0x80 is an invalid standalone UTF-8 byte — json_encode throws on it.
    $bad = "hello \x80\x81 world café";
    expect(json_encode([$bad]))->toBeFalse(); // sanity: raw value is unencodable

    $clean = $m->invoke($svc, $bad);
    expect(mb_check_encoding($clean, 'UTF-8'))->toBeTrue()
        ->and(json_encode([$clean]))->not->toBeFalse()
        ->and($clean)->toContain('world café'); // valid chars preserved
});

it('builds the narrative even when Slack content has malformed UTF-8', function () {
    config()->set('services.cloudflare.account_id', 'acct');
    config()->set('services.cloudflare.api_token', 'tok');

    $client = Client::factory()->create(['name' => 'Example Client LLC']);
    $period = RetainerPeriod::factory()->create([
        'client_id' => $client->id,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
    ]);
    $ws = SlackWorkspace::factory()->create();
    $channel = SlackChannel::factory()->create([
        'workspace_id' => $ws->id,
        'client_id' => $client->id,
    ]);

    // A message carrying invalid UTF-8 bytes — like real pasted Slack content.
    SlackMessage::create([
        'workspace_id' => $ws->id,
        'channel_id' => $channel->id,
        'message_ts' => '1747000000.0001',
        'user_id' => 'U1',
        'user_name' => 'cory',
        'content' => "Plugin update shipped \x80\x9d and tested",
        'client_id' => $client->id,
        'sent_at' => '2026-05-15 10:00:00',
    ]);

    $sent = false;
    Http::fake([
        'api.cloudflare.com/*' => function () use (&$sent) {
            $sent = true;

            return Http::response([
                'success' => true,
                'result' => ['response' => json_encode([
                    'topics' => [[
                        'title' => 'Plugin update', 'summary' => 's', 'estimated_hours' => 2.0,
                        'status' => 'completed', 'start_date' => '2026-05-15', 'end_date' => '2026-05-15', 'evidence' => [],
                    ]],
                    'value_summary' => 'Shipped.',
                ])],
            ], 200);
        },
    ]);

    $result = app(RetainerNarrativeService::class)->buildNarrative($period, force: true);

    // Before the fix, json_encode threw and the call never sent → warnings.
    expect($sent)->toBeTrue()
        ->and($result['warnings'])->toBe([])
        ->and($result['topics'])->toHaveCount(1);
});
