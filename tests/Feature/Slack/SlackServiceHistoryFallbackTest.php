<?php

use App\Models\SlackWorkspace;
use App\Services\Slack\SlackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function workspaceWithTokens(): SlackWorkspace
{
    return SlackWorkspace::factory()->create([
        'access_token' => 'xoxb-bot-token',
        'user_access_token' => 'xoxp-user-token',
    ]);
}

it('falls back to the user token when the bot returns ok but no messages', function () {
    // Group DMs (mpims): the bot isn't a member and Slack returns ok=true with
    // an empty list. The user token, which can read the DM, must be tried.
    $ws = workspaceWithTokens();

    Http::fakeSequence('slack.com/api/conversations.history*')
        ->push(['ok' => true, 'messages' => []])
        ->push(['ok' => true, 'messages' => [['ts' => '1747000000.0001', 'text' => 'real msg']]]);

    $result = app(SlackService::class)->getChannelHistoryWithError($ws, 'C00EXAMPLE01', null, 100);

    expect($result['ok'])->toBeTrue()
        ->and($result['messages'])->toHaveCount(1)
        ->and($result['messages'][0]['text'])->toBe('real msg');
});

it('keeps the bot result when the bot can see messages', function () {
    $ws = workspaceWithTokens();

    Http::fake([
        'slack.com/api/conversations.history*' => Http::response(['ok' => true, 'messages' => [
            ['ts' => '1747000000.0001', 'text' => 'bot-visible'],
        ]]),
    ]);

    $result = app(SlackService::class)->getChannelHistoryWithError($ws, 'C123', null, 100);

    expect($result['messages'])->toHaveCount(1)
        ->and($result['messages'][0]['text'])->toBe('bot-visible');
    // Only one request — no needless user-token retry.
    Http::assertSentCount(1);
});

it('falls back to the user token on an explicit access error', function () {
    $ws = workspaceWithTokens();

    Http::fakeSequence('slack.com/api/conversations.history*')
        ->push(['ok' => false, 'error' => 'not_in_channel'])
        ->push(['ok' => true, 'messages' => [['ts' => '1', 'text' => 'via user']]]);

    $result = app(SlackService::class)->getChannelHistoryWithError($ws, 'C123', null, 100);

    expect($result['ok'])->toBeTrue()
        ->and($result['messages'][0]['text'])->toBe('via user');
});

it('returns ok empty when neither token sees messages', function () {
    $ws = workspaceWithTokens();

    Http::fake([
        'slack.com/api/conversations.history*' => Http::response(['ok' => true, 'messages' => []]),
    ]);

    $result = app(SlackService::class)->getChannelHistoryWithError($ws, 'C123', null, 100);

    expect($result['ok'])->toBeTrue()
        ->and($result['messages'])->toBe([]);
});

it('does not retry when there is no user token', function () {
    $ws = SlackWorkspace::factory()->create([
        'access_token' => 'xoxb-bot-token',
        'user_access_token' => null,
    ]);

    Http::fake([
        'slack.com/api/conversations.history*' => Http::response(['ok' => true, 'messages' => []]),
    ]);

    $result = app(SlackService::class)->getChannelHistoryWithError($ws, 'C123', null, 100);

    expect($result['ok'])->toBeTrue();
    Http::assertSentCount(1);
});
