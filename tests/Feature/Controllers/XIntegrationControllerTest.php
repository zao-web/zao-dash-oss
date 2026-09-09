<?php

use App\Models\User;
use App\Models\XCredential;
use App\Services\X\XService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->xService = Mockery::mock(XService::class);
    $this->app->instance(XService::class, $this->xService);
});

// OAuth redirect tests
test('redirect generates x oauth url', function () {
    $authUrl = 'https://twitter.com/i/oauth2/authorize?client_id=123';

    $this->xService->shouldReceive('getAuthUrl')
        ->once()
        ->with(route('x.callback'))
        ->andReturn($authUrl);

    $response = $this->get(route('x.redirect'));

    $response->assertRedirect($authUrl);
});

// OAuth callback tests
test('callback handles oauth error', function () {
    $response = $this->get(route('x.callback', [
        'error' => 'access_denied',
        'error_description' => 'User denied access',
    ]));

    $response->assertRedirect(route('settings.integrations'));
    $response->assertSessionHas('error');
    expect(session('error'))->toContain('User denied access');
});

test('callback exchanges code for tokens and stores credential', function () {
    $tokenData = [
        'access_token' => 'x-access-token',
        'refresh_token' => 'x-refresh-token',
        'expires_in' => 7200,
        'scope' => 'tweet.read tweet.write users.read',
    ];

    $profile = [
        'id' => 'x-user-id',
        'username' => 'johndoe',
        'name' => 'John Doe',
        'profile_image_url' => 'https://pbs.twimg.com/profile.jpg',
        'description' => 'Bio text',
        'verified' => true,
        'public_metrics' => [
            'followers_count' => 1000,
            'following_count' => 500,
            'tweet_count' => 5000,
        ],
    ];

    $this->xService->shouldReceive('exchangeCodeForToken')
        ->once()
        ->with('auth-code', route('x.callback'))
        ->andReturn($tokenData);

    $this->xService->shouldReceive('getMe')
        ->once()
        ->andReturn($profile);

    $response = $this->get(route('x.callback', [
        'code' => 'auth-code',
    ]));

    $response->assertRedirect(route('settings.integrations'));
    $response->assertSessionHas('success', 'X (Twitter) connected successfully!');

    $credential = XCredential::where('user_id', $this->user->id)->first();
    expect($credential)->not->toBeNull();
    expect($credential->x_user_id)->toBe('x-user-id');
    expect($credential->username)->toBe('johndoe');
    expect($credential->followers_count)->toBe(1000);
    expect($credential->verified)->toBeTrue();
});

test('callback handles token exchange failure', function () {
    $this->xService->shouldReceive('exchangeCodeForToken')
        ->once()
        ->andThrow(new Exception('Invalid authorization code'));

    $response = $this->get(route('x.callback', [
        'code' => 'invalid-code',
    ]));

    $response->assertRedirect(route('settings.integrations'));
    $response->assertSessionHas('error');
    expect(session('error'))->toContain('Failed to connect X');
});

test('callback updates existing credential', function () {
    XCredential::factory()->for($this->user)->create([
        'x_user_id' => 'x-user-id',
        'username' => 'oldusername',
        'followers_count' => 500,
    ]);

    $tokenData = [
        'access_token' => 'new-token',
        'refresh_token' => 'new-refresh',
        'expires_in' => 7200,
        'scope' => 'tweet.read tweet.write',
    ];

    $profile = [
        'id' => 'x-user-id',
        'username' => 'newusername',
        'name' => 'New Name',
        'public_metrics' => [
            'followers_count' => 2000,
            'following_count' => 600,
            'tweet_count' => 6000,
        ],
    ];

    $this->xService->shouldReceive('exchangeCodeForToken')
        ->once()
        ->andReturn($tokenData);

    $this->xService->shouldReceive('getMe')
        ->once()
        ->andReturn($profile);

    $response = $this->get(route('x.callback', [
        'code' => 'auth-code',
    ]));

    $response->assertRedirect(route('settings.integrations'));

    expect(XCredential::where('user_id', $this->user->id)->count())->toBe(1);
    $credential = XCredential::where('user_id', $this->user->id)->first();
    expect($credential->username)->toBe('newusername');
    expect($credential->followers_count)->toBe(2000);
});

// Status tests
test('status returns disconnected when no credential', function () {
    $response = $this->getJson(route('x.status'));

    $response->assertStatus(200);
    $response->assertJson(['connected' => false]);
});

test('status returns connected with credential details', function () {
    XCredential::factory()->for($this->user)->create([
        'username' => 'johndoe',
        'name' => 'John Doe',
        'profile_image_url' => 'https://example.com/profile.jpg',
        'verified' => true,
        'followers_count' => 1500,
        'following_count' => 300,
        'tweet_count' => 4500,
        'is_active' => true,
        'token_expires_at' => now()->addWeek(),
    ]);

    $response = $this->getJson(route('x.status'));

    $response->assertStatus(200);
    $response->assertJson([
        'connected' => true,
        'username' => 'johndoe',
        'name' => 'John Doe',
        'verified' => true,
        'followers_count' => 1500,
        'following_count' => 300,
        'tweet_count' => 4500,
        'is_active' => true,
    ]);
});

test('status requires authentication', function () {
    auth()->logout();

    $response = $this->getJson(route('x.status'));

    $response->assertStatus(401);
});

// Disconnect tests
test('disconnect removes credential', function () {
    $credential = XCredential::factory()->for($this->user)->create();

    $response = $this->deleteJson(route('x.disconnect'));

    $response->assertStatus(200);
    $response->assertJsonPath('message', 'X disconnected');
    expect(XCredential::find($credential->id))->toBeNull();
});

test('disconnect handles missing credential gracefully', function () {
    $response = $this->deleteJson(route('x.disconnect'));

    $response->assertStatus(200);
    $response->assertJsonPath('message', 'X disconnected');
});

// Create tweet tests
test('create tweet requires credential', function () {
    $response = $this->postJson(route('x.createTweet'), [
        'text' => 'Test tweet',
    ]);

    $response->assertStatus(400);
    $response->assertJsonPath('error', 'X not connected');
});

test('create tweet validates required fields', function () {
    XCredential::factory()->for($this->user)->create();

    $response = $this->postJson(route('x.createTweet'), []);

    $response->assertStatus(422);
});

test('create tweet validates max length', function () {
    XCredential::factory()->for($this->user)->create();

    $response = $this->postJson(route('x.createTweet'), [
        'text' => str_repeat('a', 281),
    ]);

    $response->assertStatus(422);
});

test('create tweet creates simple tweet', function () {
    $credential = XCredential::factory()->for($this->user)->create();

    $result = ['id' => 'tweet123'];

    $this->xService->shouldReceive('createTweet')
        ->once()
        ->with(
            Mockery::on(fn ($c) => $c->id === $credential->id),
            'Hello X!',
            ['reply_to' => null, 'quote_tweet_id' => null]
        )
        ->andReturn($result);

    $response = $this->postJson(route('x.createTweet'), [
        'text' => 'Hello X!',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('tweet_id', 'tweet123');
});

test('create tweet creates reply', function () {
    $credential = XCredential::factory()->for($this->user)->create();

    $result = ['id' => 'tweet456'];

    $this->xService->shouldReceive('createTweet')
        ->once()
        ->with(
            Mockery::on(fn ($c) => $c->id === $credential->id),
            'Reply text',
            ['reply_to' => 'parent123', 'quote_tweet_id' => null]
        )
        ->andReturn($result);

    $response = $this->postJson(route('x.createTweet'), [
        'text' => 'Reply text',
        'reply_to' => 'parent123',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('tweet_id', 'tweet456');
});

test('create tweet creates quote tweet', function () {
    $credential = XCredential::factory()->for($this->user)->create();

    $result = ['id' => 'tweet789'];

    $this->xService->shouldReceive('createTweet')
        ->once()
        ->with(
            Mockery::on(fn ($c) => $c->id === $credential->id),
            'Quote text',
            ['reply_to' => null, 'quote_tweet_id' => 'quoted123']
        )
        ->andReturn($result);

    $response = $this->postJson(route('x.createTweet'), [
        'text' => 'Quote text',
        'quote_tweet_id' => 'quoted123',
    ]);

    $response->assertStatus(200);
});

test('create tweet handles api errors', function () {
    XCredential::factory()->for($this->user)->create();

    $this->xService->shouldReceive('createTweet')
        ->once()
        ->andThrow(new Exception('X API error'));

    $response = $this->postJson(route('x.createTweet'), [
        'text' => 'Test tweet',
    ]);

    $response->assertStatus(500);
    expect($response->json('error'))->toContain('Failed to create tweet');
});

// Create thread tests
test('create thread requires credential', function () {
    $response = $this->postJson(route('x.createThread'), [
        'tweets' => ['Tweet 1', 'Tweet 2'],
    ]);

    $response->assertStatus(400);
});

test('create thread validates minimum tweets', function () {
    XCredential::factory()->for($this->user)->create();

    $response = $this->postJson(route('x.createThread'), [
        'tweets' => ['Only one tweet'],
    ]);

    $response->assertStatus(422);
});

test('create thread validates maximum tweets', function () {
    XCredential::factory()->for($this->user)->create();

    $response = $this->postJson(route('x.createThread'), [
        'tweets' => array_fill(0, 26, 'Tweet'),
    ]);

    $response->assertStatus(422);
});

test('create thread creates tweet thread', function () {
    $credential = XCredential::factory()->for($this->user)->create();

    $results = [
        ['id' => 'tweet1'],
        ['id' => 'tweet2'],
        ['id' => 'tweet3'],
    ];

    $this->xService->shouldReceive('createThread')
        ->once()
        ->with(
            Mockery::on(fn ($c) => $c->id === $credential->id),
            ['First tweet', 'Second tweet', 'Third tweet']
        )
        ->andReturn($results);

    $response = $this->postJson(route('x.createThread'), [
        'tweets' => ['First tweet', 'Second tweet', 'Third tweet'],
    ]);

    $response->assertStatus(200);
    $response->assertJsonCount(3, 'tweets');
});

test('create thread handles api errors', function () {
    XCredential::factory()->for($this->user)->create();

    $this->xService->shouldReceive('createThread')
        ->once()
        ->andThrow(new Exception('Thread creation failed'));

    $response = $this->postJson(route('x.createThread'), [
        'tweets' => ['Tweet 1', 'Tweet 2'],
    ]);

    $response->assertStatus(500);
});

// Get timeline tests
test('get timeline requires credential', function () {
    $response = $this->getJson(route('x.getTimeline'));

    $response->assertStatus(400);
    $response->assertJsonPath('error', 'X not connected');
});

test('get timeline returns user tweets', function () {
    $credential = XCredential::factory()->for($this->user)->create();

    $tweets = [
        ['id' => 'tweet1', 'text' => 'First tweet'],
        ['id' => 'tweet2', 'text' => 'Second tweet'],
    ];

    $this->xService->shouldReceive('getUserTimeline')
        ->once()
        ->with(Mockery::on(fn ($c) => $c->id === $credential->id), 20)
        ->andReturn($tweets);

    $response = $this->getJson(route('x.getTimeline'));

    $response->assertStatus(200);
    $response->assertJsonCount(2, 'tweets');
});

test('get timeline handles api errors', function () {
    XCredential::factory()->for($this->user)->create();

    $this->xService->shouldReceive('getUserTimeline')
        ->once()
        ->andThrow(new Exception('API error'));

    $response = $this->getJson(route('x.getTimeline'));

    $response->assertStatus(500);
});

// Refresh stats tests
test('refresh stats requires credential', function () {
    $response = $this->postJson(route('x.refreshStats'));

    $response->assertStatus(400);
    $response->assertJsonPath('error', 'X not connected');
});

test('refresh stats updates user metrics', function () {
    $credential = XCredential::factory()->for($this->user)->create([
        'followers_count' => 1000,
        'following_count' => 500,
        'tweet_count' => 3000,
    ]);

    $profile = [
        'id' => $credential->x_user_id,
        'username' => $credential->username,
        'public_metrics' => [
            'followers_count' => 1500,
            'following_count' => 600,
            'tweet_count' => 3500,
        ],
    ];

    $this->xService->shouldReceive('getMe')
        ->once()
        ->with(Mockery::on(fn ($c) => $c->id === $credential->id))
        ->andReturn($profile);

    $response = $this->postJson(route('x.refreshStats'));

    $response->assertStatus(200);
    $response->assertJsonPath('followers_count', 1500);
    $response->assertJsonPath('following_count', 600);
    $response->assertJsonPath('tweet_count', 3500);

    expect($credential->fresh()->followers_count)->toBe(1500);
    expect($credential->fresh()->last_synced_at)->not->toBeNull();
});

test('refresh stats handles api errors', function () {
    XCredential::factory()->for($this->user)->create();

    $this->xService->shouldReceive('getMe')
        ->once()
        ->andThrow(new Exception('API error'));

    $response = $this->postJson(route('x.refreshStats'));

    $response->assertStatus(500);
});
