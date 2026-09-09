<?php

namespace Tests\Unit\Services\QuickBooks;

use App\Models\QuickBooksConnection;
use App\Models\User;
use App\Services\QuickBooks\QuickBooksOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuickBooksOAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected QuickBooksOAuthService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.quickbooks.client_id' => 'test-qb-client-id',
            'services.quickbooks.client_secret' => 'test-qb-client-secret',
            'services.quickbooks.redirect_uri' => 'https://example.com/auth/quickbooks/callback',
            'services.quickbooks.environment' => 'sandbox',
        ]);

        $this->service = new QuickBooksOAuthService;
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_generates_authorization_url_with_correct_parameters()
    {
        $url = $this->service->getAuthorizationUrl('test-state-123');

        $this->assertStringContainsString('https://appcenter.intuit.com/connect/oauth2', $url);
        $this->assertStringContainsString('client_id='.config('services.quickbooks.client_id'), $url);
        $this->assertStringContainsString('redirect_uri='.urlencode(config('services.quickbooks.redirect_uri')), $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('scope=com.intuit.quickbooks.accounting', $url);
        $this->assertStringContainsString('state=test-state-123', $url);
    }

    /** @test */
    public function it_exchanges_code_for_tokens_successfully()
    {
        Http::fake([
            'oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
                'access_token' => 'qb-access-token',
                'refresh_token' => 'qb-refresh-token',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 8726400,
                'token_type' => 'bearer',
            ], 200),
        ]);

        $tokens = $this->service->exchangeCodeForTokens('auth-code-123', 'realm-456');

        $this->assertEquals('qb-access-token', $tokens['access_token']);
        $this->assertEquals('qb-refresh-token', $tokens['refresh_token']);
        $this->assertEquals(3600, $tokens['expires_in']);
        $this->assertEquals('realm-456', $tokens['realm_id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'tokens/bearer') &&
                   $request['grant_type'] === 'authorization_code' &&
                   $request['code'] === 'auth-code-123' &&
                   $request['redirect_uri'] === config('services.quickbooks.redirect_uri') &&
                   $request->hasHeader('Authorization');
        });
    }

    /** @test */
    public function it_uses_basic_auth_for_token_exchange()
    {
        Http::fake([
            'oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
                'access_token' => 'token',
                'refresh_token' => 'refresh',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 8726400,
            ], 200),
        ]);

        $this->service->exchangeCodeForTokens('code', 'realm');

        Http::assertSent(function ($request) {
            $auth = base64_encode(config('services.quickbooks.client_id').':'.config('services.quickbooks.client_secret'));

            return $request->hasHeader('Authorization', 'Basic '.$auth);
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_code_exchange()
    {
        Http::fake([
            'oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
                'error' => 'invalid_grant',
            ], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to exchange code for tokens');

        $this->service->exchangeCodeForTokens('invalid-code', 'realm');
    }

    /** @test */
    public function it_refreshes_access_token_successfully()
    {
        $connection = QuickBooksConnection::factory()->create([
            'user_id' => $this->user->id,
            'refresh_token' => 'old-refresh-token',
            'access_token' => 'old-access-token',
            'access_token_expires_at' => now()->subHour(),
            'refresh_token_expires_at' => now()->addDays(90),
        ]);

        Http::fake([
            'oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 8726400,
            ], 200),
        ]);

        $refreshed = $this->service->refreshAccessToken($connection);

        $this->assertEquals('new-access-token', $refreshed->access_token);
        $this->assertEquals('new-refresh-token', $refreshed->refresh_token);
        $this->assertNotNull($refreshed->access_token_expires_at);
        $this->assertNotNull($refreshed->refresh_token_expires_at);
        $this->assertTrue($refreshed->access_token_expires_at->isFuture());

        Http::assertSent(function ($request) {
            return $request['grant_type'] === 'refresh_token' &&
                   $request['refresh_token'] === 'old-refresh-token' &&
                   $request->hasHeader('Authorization');
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_token_refresh()
    {
        $connection = QuickBooksConnection::factory()->create([
            'refresh_token' => 'invalid-refresh-token',
        ]);

        Http::fake([
            'oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
                'error' => 'invalid_grant',
            ], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to refresh token');

        $this->service->refreshAccessToken($connection);
    }

    /** @test */
    public function it_stores_connection_successfully()
    {
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/*/companyinfo/*' => Http::response([
                'CompanyInfo' => [
                    'CompanyName' => 'Test Company LLC',
                ],
            ], 200),
        ]);

        config(['services.quickbooks.environment' => 'sandbox']);

        $tokenData = [
            'access_token' => 'qb-access-token',
            'refresh_token' => 'qb-refresh-token',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
            'realm_id' => 'realm-123',
        ];

        $connection = $this->service->storeConnection($this->user, $tokenData);

        $this->assertDatabaseHas('quickbooks_connections', [
            'user_id' => $this->user->id,
            'realm_id' => 'realm-123',
            'company_name' => 'Test Company LLC',
        ]);

        $this->assertEquals('qb-access-token', $connection->access_token);
        $this->assertEquals('qb-refresh-token', $connection->refresh_token);
    }

    /** @test */
    public function it_uses_production_api_url_when_environment_is_production()
    {
        config(['services.quickbooks.environment' => 'production']);

        Http::fake([
            'quickbooks.api.intuit.com/v3/company/*/companyinfo/*' => Http::response([
                'CompanyInfo' => [
                    'CompanyName' => 'Production Company',
                ],
            ], 200),
        ]);

        $tokenData = [
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
            'realm_id' => 'realm-123',
        ];

        $this->service->storeConnection($this->user, $tokenData);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'quickbooks.api.intuit.com') &&
                   ! str_contains($request->url(), 'sandbox');
        });
    }

    /** @test */
    public function it_handles_failed_company_info_fetch()
    {
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/*/companyinfo/*' => Http::response([], 401),
        ]);

        config(['services.quickbooks.environment' => 'sandbox']);

        $tokenData = [
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
            'realm_id' => 'realm-123',
        ];

        $connection = $this->service->storeConnection($this->user, $tokenData);

        $this->assertEquals('Unknown Company', $connection->company_name);
    }

    /** @test */
    public function it_updates_existing_connection_when_storing()
    {
        $existing = QuickBooksConnection::factory()->create([
            'user_id' => $this->user->id,
            'realm_id' => 'realm-123',
            'company_name' => 'Old Company',
        ]);

        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/*/companyinfo/*' => Http::response([
                'CompanyInfo' => [
                    'CompanyName' => 'New Company',
                ],
            ], 200),
        ]);

        config(['services.quickbooks.environment' => 'sandbox']);

        $tokenData = [
            'access_token' => 'new-token',
            'refresh_token' => 'new-refresh',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
            'realm_id' => 'realm-123',
        ];

        $connection = $this->service->storeConnection($this->user, $tokenData);

        $this->assertEquals($existing->id, $connection->id);
        $this->assertEquals('New Company', $connection->company_name);
        $this->assertEquals(1, QuickBooksConnection::count());
    }

    /** @test */
    public function it_stores_tokens_encrypted()
    {
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/*/companyinfo/*' => Http::response([
                'CompanyInfo' => ['CompanyName' => 'Test'],
            ], 200),
        ]);

        config(['services.quickbooks.environment' => 'sandbox']);

        $tokenData = [
            'access_token' => 'plain-access-token',
            'refresh_token' => 'plain-refresh-token',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400,
            'realm_id' => 'realm-123',
        ];

        $connection = $this->service->storeConnection($this->user, $tokenData);

        $rawToken = \DB::table('quickbooks_connections')
            ->where('id', $connection->id)
            ->value('access_token');

        $this->assertNotEquals('plain-access-token', $rawToken);
        $this->assertEquals('plain-access-token', $connection->access_token);
    }

    /** @test */
    public function it_gets_valid_access_token_without_refresh()
    {
        $connection = QuickBooksConnection::factory()->create([
            'access_token' => 'valid-token',
            'access_token_expires_at' => now()->addHour(),
        ]);

        $token = $this->service->getValidAccessToken($connection);

        $this->assertEquals('valid-token', $token);
    }

    /** @test */
    public function it_refreshes_token_when_expired()
    {
        $connection = QuickBooksConnection::factory()->create([
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-token',
            'access_token_expires_at' => now()->subMinutes(15),
        ]);

        Http::fake([
            'oauth.platform.intuit.com/oauth2/v1/tokens/bearer' => Http::response([
                'access_token' => 'refreshed-token',
                'refresh_token' => 'new-refresh',
                'expires_in' => 3600,
                'x_refresh_token_expires_in' => 8726400,
            ], 200),
        ]);

        $token = $this->service->getValidAccessToken($connection);

        $this->assertEquals('refreshed-token', $token);
    }

    /** @test */
    public function it_detects_access_token_expiration()
    {
        $expired = QuickBooksConnection::factory()->create([
            'access_token_expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($expired->isAccessTokenExpired());

        $valid = QuickBooksConnection::factory()->create([
            'access_token_expires_at' => now()->addHour(),
        ]);

        $this->assertFalse($valid->isAccessTokenExpired());
    }

    /** @test */
    public function it_detects_when_token_needs_refresh()
    {
        $needsRefresh = QuickBooksConnection::factory()->create([
            'access_token_expires_at' => now()->addMinutes(5),
        ]);

        $this->assertTrue($needsRefresh->needsTokenRefresh());

        $doesNotNeedRefresh = QuickBooksConnection::factory()->create([
            'access_token_expires_at' => now()->addHour(),
        ]);

        $this->assertFalse($doesNotNeedRefresh->needsTokenRefresh());
    }

    /** @test */
    public function it_detects_refresh_token_expiring_soon()
    {
        $expiring = QuickBooksConnection::factory()->create([
            'refresh_token_expires_at' => now()->addDays(5),
        ]);

        $this->assertTrue($expiring->isRefreshTokenExpiring());

        $notExpiring = QuickBooksConnection::factory()->create([
            'refresh_token_expires_at' => now()->addDays(30),
        ]);

        $this->assertFalse($notExpiring->isRefreshTokenExpiring());
    }

    /** @test */
    public function it_calculates_token_expiration_correctly()
    {
        Http::fake([
            'sandbox-quickbooks.api.intuit.com/v3/company/*/companyinfo/*' => Http::response([
                'CompanyInfo' => ['CompanyName' => 'Test'],
            ], 200),
        ]);

        config(['services.quickbooks.environment' => 'sandbox']);

        $tokenData = [
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_in' => 3600,
            'x_refresh_token_expires_in' => 8726400, // 100 days
            'realm_id' => 'realm-123',
        ];

        $connection = $this->service->storeConnection($this->user, $tokenData);

        $this->assertTrue($connection->access_token_expires_at->isBetween(
            now()->addSeconds(3595),
            now()->addSeconds(3605)
        ));

        $this->assertTrue($connection->refresh_token_expires_at->isBetween(
            now()->addSeconds(8726395),
            now()->addSeconds(8726405)
        ));
    }
}
