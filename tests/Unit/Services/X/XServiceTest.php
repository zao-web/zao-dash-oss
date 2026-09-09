<?php

namespace Tests\Unit\Services\X;

use App\Models\User;
use App\Models\XCredential;
use App\Services\X\XService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class XServiceTest extends TestCase
{
    use RefreshDatabase;

    protected XService $service;

    protected XCredential $credential;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.x.client_id' => 'test-client-id',
            'services.x.client_secret' => 'test-client-secret',
        ]);

        $this->service = new XService;

        $user = User::factory()->create();
        $this->credential = XCredential::factory()->create([
            'user_id' => $user->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'x_user_id' => '123456789',
            'token_expires_at' => now()->addHours(2),
        ]);
    }

    /** @test */
    public function it_generates_auth_url_with_pkce()
    {
        $url = $this->service->getAuthUrl('https://example.com/callback');

        $this->assertStringContainsString('twitter.com/i/oauth2/authorize', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fexample.com%2Fcallback', $url);
        $this->assertStringContainsString('code_challenge=', $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
        $this->assertStringContainsString('scope=tweet.read+tweet.write+users.read+offline.access', $url);
        $this->assertNotNull(session('x_code_verifier'));
    }

    /** @test */
    public function it_exchanges_code_for_token()
    {
        session(['x_code_verifier' => 'test-verifier']);

        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 7200,
            ], 200),
        ]);

        $result = $this->service->exchangeCodeForToken('auth-code', 'https://example.com/callback');

        $this->assertEquals('new-access-token', $result['access_token']);
        $this->assertEquals('new-refresh-token', $result['refresh_token']);
        $this->assertNull(session('x_code_verifier'));
    }

    /** @test */
    public function it_throws_exception_on_failed_token_exchange()
    {
        session(['x_code_verifier' => 'test-verifier']);

        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response([
                'error' => 'invalid_grant',
            ], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to exchange code for token');

        $this->service->exchangeCodeForToken('invalid-code', 'https://example.com/callback');
    }

    /** @test */
    public function it_refreshes_token()
    {
        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response([
                'access_token' => 'refreshed-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 7200,
            ], 200),
        ]);

        $result = $this->service->refreshToken($this->credential);

        $this->assertEquals('refreshed-token', $result['access_token']);

        $this->credential->refresh();
        $this->assertEquals('refreshed-token', $this->credential->access_token);
        $this->assertEquals('new-refresh-token', $this->credential->refresh_token);
        $this->assertNotNull($this->credential->token_expires_at);
    }

    /** @test */
    public function it_throws_exception_on_failed_token_refresh()
    {
        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response([
                'error' => 'invalid_grant',
            ], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to refresh token');

        $this->service->refreshToken($this->credential);
    }

    /** @test */
    public function it_gets_user_info()
    {
        Http::fake([
            'api.twitter.com/2/users/me*' => Http::response([
                'data' => [
                    'id' => '123456789',
                    'name' => 'John Doe',
                    'username' => 'johndoe',
                    'verified' => true,
                ],
            ], 200),
        ]);

        $user = $this->service->getMe($this->credential);

        $this->assertEquals('123456789', $user['id']);
        $this->assertEquals('John Doe', $user['name']);
        $this->assertEquals('johndoe', $user['username']);
        $this->assertTrue($user['verified']);
    }

    /** @test */
    public function it_throws_exception_on_failed_user_fetch()
    {
        Http::fake([
            'api.twitter.com/2/users/me*' => Http::response('Unauthorized', 401),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get user');

        $this->service->getMe($this->credential);
    }

    /** @test */
    public function it_creates_tweet()
    {
        Http::fake([
            'api.twitter.com/2/tweets' => Http::response([
                'data' => [
                    'id' => '987654321',
                    'text' => 'Hello Twitter!',
                ],
            ], 201),
        ]);

        $result = $this->service->createTweet($this->credential, 'Hello Twitter!');

        $this->assertEquals('987654321', $result['id']);
        $this->assertEquals('Hello Twitter!', $result['text']);
    }

    /** @test */
    public function it_creates_tweet_with_reply()
    {
        Http::fake([
            'api.twitter.com/2/tweets' => Http::response([
                'data' => ['id' => '111', 'text' => 'Reply tweet'],
            ], 201),
        ]);

        $this->service->createTweet($this->credential, 'Reply tweet', ['reply_to' => '999']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['reply']['in_reply_to_tweet_id']) &&
                   $body['reply']['in_reply_to_tweet_id'] === '999';
        });
    }

    /** @test */
    public function it_creates_tweet_with_quote()
    {
        Http::fake([
            'api.twitter.com/2/tweets' => Http::response([
                'data' => ['id' => '222', 'text' => 'Quote tweet'],
            ], 201),
        ]);

        $this->service->createTweet($this->credential, 'Quote tweet', ['quote_tweet_id' => '888']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['quote_tweet_id']) && $body['quote_tweet_id'] === '888';
        });
    }

    /** @test */
    public function it_creates_tweet_with_poll()
    {
        Http::fake([
            'api.twitter.com/2/tweets' => Http::response([
                'data' => ['id' => '333', 'text' => 'Poll tweet'],
            ], 201),
        ]);

        $this->service->createTweet($this->credential, 'Poll tweet', [
            'poll' => [
                'options' => ['Option 1', 'Option 2'],
                'duration_minutes' => 60,
            ],
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['poll']) &&
                   $body['poll']['duration_minutes'] === 60 &&
                   count($body['poll']['options']) === 2;
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_tweet_creation()
    {
        Http::fake([
            'api.twitter.com/2/tweets' => Http::response([
                'errors' => [['message' => 'Duplicate tweet']],
            ], 403),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to create tweet');

        $this->service->createTweet($this->credential, 'Test');
    }

    /** @test */
    public function it_creates_thread()
    {
        Http::fake([
            'api.twitter.com/2/tweets' => Http::sequence()
                ->push(['data' => ['id' => '1', 'text' => 'Tweet 1']], 201)
                ->push(['data' => ['id' => '2', 'text' => 'Tweet 2']], 201)
                ->push(['data' => ['id' => '3', 'text' => 'Tweet 3']], 201),
        ]);

        $results = $this->service->createThread($this->credential, [
            'Tweet 1',
            'Tweet 2',
            'Tweet 3',
        ]);

        $this->assertCount(3, $results);
        $this->assertEquals('1', $results[0]['id']);
        $this->assertEquals('2', $results[1]['id']);
        $this->assertEquals('3', $results[2]['id']);
    }

    /** @test */
    public function it_deletes_tweet()
    {
        Http::fake([
            'api.twitter.com/2/tweets/*' => Http::response([
                'data' => ['deleted' => true],
            ], 200),
        ]);

        $result = $this->service->deleteTweet($this->credential, '123');

        $this->assertTrue($result);
    }

    /** @test */
    public function it_returns_false_on_failed_delete()
    {
        Http::fake([
            'api.twitter.com/2/tweets/*' => Http::response('Error', 500),
        ]);

        $result = $this->service->deleteTweet($this->credential, '123');

        $this->assertFalse($result);
    }

    /** @test */
    public function it_gets_user_timeline()
    {
        Http::fake([
            'api.twitter.com/2/users/*/tweets*' => Http::response([
                'data' => [
                    ['id' => '1', 'text' => 'Tweet 1'],
                    ['id' => '2', 'text' => 'Tweet 2'],
                ],
            ], 200),
        ]);

        $tweets = $this->service->getUserTimeline($this->credential, 10);

        $this->assertCount(2, $tweets);
        $this->assertEquals('Tweet 1', $tweets[0]['text']);
    }

    /** @test */
    public function it_throws_exception_on_failed_timeline_fetch()
    {
        Http::fake([
            'api.twitter.com/2/users/*/tweets*' => Http::response('Error', 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get timeline');

        $this->service->getUserTimeline($this->credential);
    }

    /** @test */
    public function it_searches_tweets()
    {
        Http::fake([
            'api.twitter.com/2/tweets/search/recent*' => Http::response([
                'data' => [
                    ['id' => '1', 'text' => 'Match 1'],
                    ['id' => '2', 'text' => 'Match 2'],
                ],
            ], 200),
        ]);

        $tweets = $this->service->searchTweets($this->credential, 'Laravel', 10);

        $this->assertCount(2, $tweets);
    }

    /** @test */
    public function it_throws_exception_on_failed_search()
    {
        Http::fake([
            'api.twitter.com/2/tweets/search/recent*' => Http::response('Error', 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to search tweets');

        $this->service->searchTweets($this->credential, 'test');
    }

    /** @test */
    public function it_refreshes_token_when_expired()
    {
        $this->credential->update(['token_expires_at' => now()->subHour()]);

        Http::fake([
            'api.twitter.com/2/oauth2/token' => Http::response([
                'access_token' => 'new-token',
                'refresh_token' => 'new-refresh',
                'expires_in' => 7200,
            ], 200),
            'api.twitter.com/2/users/me*' => Http::response([
                'data' => ['id' => '123'],
            ], 200),
        ]);

        $this->service->getMe($this->credential);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'oauth2/token');
        });
    }

    /** @test */
    public function it_sends_authorization_header()
    {
        Http::fake([
            'api.twitter.com/*' => Http::response(['data' => 'test'], 200),
        ]);

        $this->service->getMe($this->credential);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-access-token');
        });
    }
}
