<?php

namespace Tests\Unit\Services\Google;

use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\Google\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleOAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected GoogleOAuthService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-google-client-id',
            'services.google.client_secret' => 'test-google-client-secret',
            'services.google.redirect_uri' => 'https://example.com/auth/google/callback',
        ]);

        $this->service = new GoogleOAuthService;
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_generates_authorization_url_with_correct_scopes()
    {
        $url = $this->service->getAuthUrl('test-state');

        $this->assertStringContainsString('https://accounts.google.com/o/oauth2/v2/auth', $url);
        $this->assertStringContainsString('client_id='.config('services.google.client_id'), $url);
        $this->assertStringContainsString('redirect_uri='.urlencode(config('services.google.redirect_uri')), $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('state=test-state', $url);
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('scope=', $url);
        $this->assertStringContainsString('gmail.readonly', $url);
        $this->assertStringContainsString('calendar.readonly', $url);
        $this->assertStringContainsString('drive.readonly', $url);
        $this->assertStringContainsString('webmasters.readonly', $url);
        $this->assertStringContainsString('analytics.readonly', $url);
    }

    /** @test */
    public function it_generates_random_state_when_not_provided()
    {
        $url1 = $this->service->getAuthUrl();
        $url2 = $this->service->getAuthUrl();

        $this->assertNotEquals($url1, $url2);
        $this->assertStringContainsString('state=', $url1);
        $this->assertStringContainsString('state=', $url2);
    }

    /** @test */
    public function it_exchanges_code_for_tokens_successfully()
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.test-access-token',
                'refresh_token' => 'test-refresh-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $tokens = $this->service->exchangeCodeForTokens('auth-code-123');

        $this->assertEquals('ya29.test-access-token', $tokens['access_token']);
        $this->assertEquals('test-refresh-token', $tokens['refresh_token']);
        $this->assertEquals(3600, $tokens['expires_in']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/token' &&
                   $request['code'] === 'auth-code-123' &&
                   $request['grant_type'] === 'authorization_code' &&
                   $request['client_id'] === config('services.google.client_id') &&
                   $request['client_secret'] === config('services.google.client_secret') &&
                   $request['redirect_uri'] === config('services.google.redirect_uri');
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_code_exchange()
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Bad Request',
            ], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to exchange code for tokens');

        $this->service->exchangeCodeForTokens('invalid-code');
    }

    /** @test */
    public function it_refreshes_access_token_successfully()
    {
        $credential = GoogleCredential::factory()->create([
            'user_id' => $this->user->id,
            'refresh_token' => 'old-refresh-token',
            'access_token' => 'old-access-token',
            'expires_at' => now()->subHour(),
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access-token',
                'expires_in' => 3600,
                'scope' => 'https://www.googleapis.com/auth/gmail.readonly',
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $refreshed = $this->service->refreshAccessToken($credential);

        $this->assertEquals('new-access-token', $refreshed->access_token);
        $this->assertNotNull($refreshed->expires_at);
        $this->assertTrue($refreshed->expires_at->isFuture());

        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/token' &&
                   $request['grant_type'] === 'refresh_token' &&
                   $request['refresh_token'] === 'old-refresh-token' &&
                   $request['client_id'] === config('services.google.client_id') &&
                   $request['client_secret'] === config('services.google.client_secret');
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_token_refresh()
    {
        $credential = GoogleCredential::factory()->create([
            'user_id' => $this->user->id,
            'refresh_token' => 'invalid-refresh-token',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
            ], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to refresh token');

        $this->service->refreshAccessToken($credential);
    }

    /** @test */
    public function it_gets_user_info_successfully()
    {
        Http::fake([
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'id' => '123456',
                'email' => 'user@example.com',
                'verified_email' => true,
                'name' => 'Test User',
                'picture' => 'https://example.com/photo.jpg',
            ], 200),
        ]);

        $userInfo = $this->service->getUserInfo('test-access-token');

        $this->assertEquals('user@example.com', $userInfo['email']);
        $this->assertEquals('Test User', $userInfo['name']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'userinfo') &&
                   $request->hasHeader('Authorization', 'Bearer test-access-token');
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_user_info_fetch()
    {
        Http::fake([
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([], 401),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get user info');

        $this->service->getUserInfo('invalid-token');
    }

    /** @test */
    public function it_stores_credentials_successfully()
    {
        Http::fake([
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'user@example.com',
                'name' => 'Test User',
            ], 200),
        ]);

        $tokens = [
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
            'scope' => 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/calendar.readonly',
        ];

        $credential = $this->service->storeCredentials($this->user, $tokens);

        $this->assertDatabaseHas('google_credentials', [
            'user_id' => $this->user->id,
            'email' => 'user@example.com',
        ]);

        $this->assertEquals('new-access-token', $credential->access_token);
        $this->assertEquals('new-refresh-token', $credential->refresh_token);
        $this->assertIsArray($credential->scopes);
        $this->assertContains('https://www.googleapis.com/auth/gmail.readonly', $credential->scopes);
    }

    /** @test */
    public function it_updates_existing_credentials_when_storing()
    {
        $existing = GoogleCredential::factory()->create([
            'user_id' => $this->user->id,
            'access_token' => 'old-token',
            'email' => 'old@example.com',
        ]);

        Http::fake([
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'new@example.com',
            ], 200),
        ]);

        $tokens = [
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
            'scope' => 'test-scope',
        ];

        $credential = $this->service->storeCredentials($this->user, $tokens);

        $this->assertEquals($existing->id, $credential->id);
        $this->assertEquals('new@example.com', $credential->email);
        $this->assertEquals(1, GoogleCredential::count());
    }

    /** @test */
    public function it_stores_tokens_encrypted()
    {
        Http::fake([
            'www.googleapis.com/oauth2/v2/userinfo' => Http::response([
                'email' => 'user@example.com',
            ], 200),
        ]);

        $tokens = [
            'access_token' => 'plain-access-token',
            'refresh_token' => 'plain-refresh-token',
            'expires_in' => 3600,
        ];

        $credential = $this->service->storeCredentials($this->user, $tokens);

        // Raw database value should be encrypted (different from plain text)
        $rawToken = \DB::table('google_credentials')
            ->where('id', $credential->id)
            ->value('access_token');

        $this->assertNotEquals('plain-access-token', $rawToken);
        $this->assertEquals('plain-access-token', $credential->access_token);
    }

    /** @test */
    public function it_gets_valid_access_token_without_refresh()
    {
        $credential = GoogleCredential::factory()->create([
            'user_id' => $this->user->id,
            'access_token' => 'valid-token',
            'expires_at' => now()->addHour(),
        ]);

        $token = $this->service->getValidAccessToken($this->user);

        $this->assertEquals('valid-token', $token);
    }

    /** @test */
    public function it_refreshes_expired_token_when_getting_valid_access_token()
    {
        $credential = GoogleCredential::factory()->create([
            'user_id' => $this->user->id,
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->subHour(),
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'refreshed-token',
                'expires_in' => 3600,
            ], 200),
        ]);

        $token = $this->service->getValidAccessToken($this->user);

        $this->assertEquals('refreshed-token', $token);
        $this->assertEquals('refreshed-token', $credential->fresh()->access_token);
    }

    /** @test */
    public function it_returns_null_when_user_has_no_credentials()
    {
        $token = $this->service->getValidAccessToken($this->user);

        $this->assertNull($token);
    }

    /** @test */
    public function it_revokes_access_successfully()
    {
        $credential = GoogleCredential::factory()->create([
            'user_id' => $this->user->id,
            'access_token' => 'token-to-revoke',
        ]);

        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);

        $result = $this->service->revokeAccess($credential);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('google_credentials', [
            'id' => $credential->id,
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'revoke') &&
                   $request['token'] === 'token-to-revoke';
        });
    }

    /** @test */
    public function it_handles_failed_revocation()
    {
        $credential = GoogleCredential::factory()->create([
            'user_id' => $this->user->id,
            'access_token' => 'token-to-revoke',
        ]);

        Http::fake([
            'oauth2.googleapis.com/revoke' => Http::response([], 400),
        ]);

        $result = $this->service->revokeAccess($credential);

        $this->assertFalse($result);
        // Credential should still be deleted even if revocation fails
        $this->assertDatabaseMissing('google_credentials', [
            'id' => $credential->id,
        ]);
    }

    /** @test */
    public function it_checks_if_user_has_valid_credentials()
    {
        $this->assertFalse($this->service->hasValidCredentials($this->user));

        GoogleCredential::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $this->assertTrue($this->service->hasValidCredentials($this->user));
    }

    /** @test */
    public function it_detects_token_expiration()
    {
        $expiredCredential = GoogleCredential::factory()->create([
            'user_id' => $this->user->id,
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($expiredCredential->isExpired());

        $validCredential = GoogleCredential::factory()->create([
            'user_id' => User::factory()->create()->id,
            'expires_at' => now()->addHour(),
        ]);

        $this->assertFalse($validCredential->isExpired());
    }
}
