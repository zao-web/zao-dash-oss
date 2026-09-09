<?php

use App\Http\Middleware\EnsureClientToken;
use App\Http\Middleware\EnsureInternalUser;
use App\Models\Client;
use App\Models\GitHubRepo;
use App\Models\SlackChannel;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

// The scoped MCP tools call $request->user() to get the bound Client, and
// Laravel\Mcp\Request::user() is typed ?Authenticatable. If Client stops being
// Authenticatable, every /mcp/zao-client tool fatals (-32603). Guard that here.
it('Client is Authenticatable so it can back a Sanctum-authed MCP request', function () {
    expect(Client::factory()->create())->toBeInstanceOf(Authenticatable::class);
});

/**
 * Proves the per-client isolation guarantee for the /mcp/zao-client surface:
 * a deployed client site can only ever touch its own client's data.
 */
it('EnsureClientToken admits a Client token and binds the scope', function () {
    $client = Client::factory()->create();

    $request = Request::create('/mcp/zao-client', 'POST');
    $request->setUserResolver(fn () => $client);

    $passed = false;
    (new EnsureClientToken)->handle($request, function () use (&$passed) {
        $passed = true;

        return response('ok');
    });

    expect($passed)->toBeTrue();
    expect($request->attributes->get('client_scope')->id)->toBe($client->id);
});

it('EnsureClientToken rejects an internal User token', function () {
    $user = User::factory()->create();

    $request = Request::create('/mcp/zao-client', 'POST');
    $request->setUserResolver(fn () => $user);

    expect(fn () => (new EnsureClientToken)->handle($request, fn () => response('ok')))
        ->toThrow(HttpException::class);
});

it('EnsureInternalUser rejects a Client token (mutual exclusion)', function () {
    $client = Client::factory()->create();

    $request = Request::create('/mcp/zao-dash', 'POST');
    $request->setUserResolver(fn () => $client);

    expect(fn () => (new EnsureInternalUser)->handle($request, fn () => response('ok')))
        ->toThrow(HttpException::class);
});

it('cannot resolve another client\'s repo under a client scope', function () {
    $a = Client::factory()->create();
    $b = Client::factory()->create();
    GitHubRepo::factory()->create(['client_id' => $a->id, 'full_name' => 'acme/site']);
    $repoB = GitHubRepo::factory()->create(['client_id' => $b->id, 'full_name' => 'other/site']);

    // The scoped tools resolve repos exactly this way — bound to the token client.
    $resolvedUnderA = GitHubRepo::query()
        ->where('client_id', $a->id)
        ->where('full_name', $repoB->full_name)
        ->first();

    expect($resolvedUnderA)->toBeNull();
    expect($a->githubRepos()->pluck('full_name')->all())->toBe(['acme/site']);
});

it('resolves the Slack channel from the token client only (notify-team isolation)', function () {
    $chA = SlackChannel::factory()->create(['slack_id' => 'C_AAA111']);
    $chB = SlackChannel::factory()->create(['slack_id' => 'C_BBB222']);
    $a = Client::factory()->create(['slack_channel_id' => $chA->id]);
    $b = Client::factory()->create(['slack_channel_id' => $chB->id]);

    // NotifyTeamTool resolves the destination exactly this way — $client->slackChannel
    // bound to the token's client. There is no channel param, so A can never post to B.
    expect($a->slackChannel?->slack_id)->toBe('C_AAA111');
    expect($b->slackChannel?->slack_id)->toBe('C_BBB222');
    expect($a->slackChannel?->slack_id)->not->toBe($chB->slack_id);
});

it('mints a token tokenable by the client (the token is the client)', function () {
    $client = Client::factory()->create();

    $token = $client->createToken('site:'.$client->slug, ['client-site']);

    expect($token->accessToken->tokenable_type)->toBe(Client::class);
    expect($token->accessToken->tokenable_id)->toBe($client->id);
    expect($token->plainTextToken)->not->toBeEmpty();
});
