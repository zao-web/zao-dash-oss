<?php

namespace Tests\Unit\Services\Harvest;

use App\Models\HarvestCredential;
use App\Models\User;
use App\Services\Harvest\HarvestOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HarvestOAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected HarvestOAuthService $service;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.harvest.client_id' => 'test-harvest-client-id',
            'services.harvest.client_secret' => 'test-harvest-client-secret',
            'services.harvest.redirect' => 'https://example.com/auth/harvest/callback',
        ]);

        $this->service = new HarvestOAuthService;
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_generates_authorization_url_with_correct_parameters()
    {
        $url = $this->service->getAuthorizationUrl('test-state-abc');

        $this->assertStringContainsString('https://id.getharvest.com/oauth2/authorize', $url);
        $this->assertStringContainsString('client_id='.config('services.harvest.client_id'), $url);
        $this->assertStringContainsString('redirect_uri='.urlencode(config('services.harvest.redirect')), $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('state=test-state-abc', $url);
    }

    /** @test */
    public function it_exchanges_code_for_tokens_successfully()
    {
        Http::fake([
            'id.getharvest.com/api/v2/oauth2/token' => Http::response([
                'access_token' => 'harvest-access-token',
                'refresh_token' => 'harvest-refresh-token',
                'expires_in' => 64800,
                'token_type' => 'bearer',
            ], 200),
        ]);

        $tokens = $this->service->exchangeCodeForTokens('auth-code-xyz');

        $this->assertEquals('harvest-access-token', $tokens['access_token']);
        $this->assertEquals('harvest-refresh-token', $tokens['refresh_token']);
        $this->assertEquals(64800, $tokens['expires_in']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'id.getharvest.com/api/v2/oauth2/token') &&
                   $request['code'] === 'auth-code-xyz' &&
                   $request['grant_type'] === 'authorization_code' &&
                   $request['client_id'] === config('services.harvest.client_id') &&
                   $request['client_secret'] === config('services.harvest.client_secret');
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_code_exchange()
    {
        Http::fake([
            'id.getharvest.com/api/v2/oauth2/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'The provided authorization grant is invalid',
            ], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to exchange code for tokens');

        $this->service->exchangeCodeForTokens('invalid-code');
    }

    /** @test */
    public function it_refreshes_access_token_successfully()
    {
        $credential = HarvestCredential::factory()->create([
            'user_id' => $this->user->id,
            'refresh_token' => 'old-harvest-refresh-token',
            'access_token' => 'old-harvest-access-token',
            'expires_at' => now()->subHour(),
        ]);

        Http::fake([
            'id.getharvest.com/api/v2/oauth2/token' => Http::response([
                'access_token' => 'new-harvest-access-token',
                'refresh_token' => 'new-harvest-refresh-token',
                'expires_in' => 64800,
                'token_type' => 'bearer',
            ], 200),
        ]);

        $refreshed = $this->service->refreshAccessToken($credential);

        $this->assertEquals('new-harvest-access-token', $refreshed->access_token);
        $this->assertEquals('new-harvest-refresh-token', $refreshed->refresh_token);
        $this->assertNotNull($refreshed->expires_at);
        $this->assertTrue($refreshed->expires_at->isFuture());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'oauth2/token') &&
                   $request['grant_type'] === 'refresh_token' &&
                   $request['refresh_token'] === 'old-harvest-refresh-token' &&
                   $request['client_id'] === config('services.harvest.client_id') &&
                   $request['client_secret'] === config('services.harvest.client_secret');
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_token_refresh()
    {
        $credential = HarvestCredential::factory()->create([
            'user_id' => $this->user->id,
            'refresh_token' => 'invalid-refresh-token',
        ]);

        Http::fake([
            'id.getharvest.com/api/v2/oauth2/token' => Http::response([
                'error' => 'invalid_grant',
            ], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to refresh token');

        $this->service->refreshAccessToken($credential);
    }

    /** @test */
    public function it_stores_credentials_successfully()
    {
        $tokenData = [
            'access_token' => 'harvest-access-token',
            'refresh_token' => 'harvest-refresh-token',
            'expires_in' => 64800,
        ];

        $accountData = [
            'id' => 12345,
            'name' => 'Test Harvest Account',
        ];

        $credential = $this->service->storeCredentials($this->user, $tokenData, $accountData);

        $this->assertDatabaseHas('harvest_credentials', [
            'user_id' => $this->user->id,
            'account_id' => 12345,
            'account_name' => 'Test Harvest Account',
        ]);

        $this->assertEquals('harvest-access-token', $credential->access_token);
        $this->assertEquals('harvest-refresh-token', $credential->refresh_token);
        $this->assertNotNull($credential->expires_at);
    }

    /** @test */
    public function it_handles_missing_account_data_when_storing_credentials()
    {
        $tokenData = [
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_in' => 64800,
        ];

        $accountData = [];

        $credential = $this->service->storeCredentials($this->user, $tokenData, $accountData);

        $this->assertDatabaseHas('harvest_credentials', [
            'user_id' => $this->user->id,
        ]);

        $this->assertNull($credential->account_id);
        $this->assertNull($credential->account_name);
    }

    /** @test */
    public function it_updates_existing_credentials_when_storing()
    {
        $existing = HarvestCredential::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => 111,
            'account_name' => 'Old Account',
        ]);

        $tokenData = [
            'access_token' => 'new-token',
            'refresh_token' => 'new-refresh',
            'expires_in' => 64800,
        ];

        $accountData = [
            'id' => 222,
            'name' => 'New Account',
        ];

        $credential = $this->service->storeCredentials($this->user, $tokenData, $accountData);

        $this->assertEquals($existing->id, $credential->id);
        $this->assertEquals(222, $credential->account_id);
        $this->assertEquals('New Account', $credential->account_name);
        $this->assertEquals(1, HarvestCredential::count());
    }

    /** @test */
    public function it_stores_tokens_encrypted()
    {
        $tokenData = [
            'access_token' => 'plain-access-token',
            'refresh_token' => 'plain-refresh-token',
            'expires_in' => 64800,
        ];

        $accountData = [
            'id' => 12345,
            'name' => 'Test Account',
        ];

        $credential = $this->service->storeCredentials($this->user, $tokenData, $accountData);

        $rawAccessToken = \DB::table('harvest_credentials')
            ->where('id', $credential->id)
            ->value('access_token');

        $rawRefreshToken = \DB::table('harvest_credentials')
            ->where('id', $credential->id)
            ->value('refresh_token');

        $this->assertNotEquals('plain-access-token', $rawAccessToken);
        $this->assertNotEquals('plain-refresh-token', $rawRefreshToken);
        $this->assertEquals('plain-access-token', $credential->access_token);
        $this->assertEquals('plain-refresh-token', $credential->refresh_token);
    }

    /** @test */
    public function it_gets_valid_token_without_refresh()
    {
        $credential = HarvestCredential::factory()->create([
            'user_id' => $this->user->id,
            'access_token' => 'valid-harvest-token',
            'expires_at' => now()->addHours(12),
        ]);

        $token = $this->service->getValidToken($this->user);

        $this->assertEquals('valid-harvest-token', $token);
    }

    /** @test */
    public function it_refreshes_expired_token_when_getting_valid_token()
    {
        $credential = HarvestCredential::factory()->create([
            'user_id' => $this->user->id,
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-token',
            'expires_at' => now()->subHour(),
        ]);

        Http::fake([
            'id.getharvest.com/api/v2/oauth2/token' => Http::response([
                'access_token' => 'refreshed-harvest-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 64800,
            ], 200),
        ]);

        $token = $this->service->getValidToken($this->user);

        $this->assertEquals('refreshed-harvest-token', $token);
        $this->assertEquals('refreshed-harvest-token', $credential->fresh()->access_token);
    }

    /** @test */
    public function it_returns_null_when_user_has_no_credentials()
    {
        $token = $this->service->getValidToken($this->user);

        $this->assertNull($token);
    }

    /** @test */
    public function it_gets_accounts_successfully()
    {
        Http::fake([
            'id.getharvest.com/api/v2/accounts' => Http::response([
                'accounts' => [
                    [
                        'id' => 12345,
                        'name' => 'Account 1',
                        'product' => 'harvest',
                    ],
                    [
                        'id' => 67890,
                        'name' => 'Account 2',
                        'product' => 'harvest',
                    ],
                ],
            ], 200),
        ]);

        $accounts = $this->service->getAccounts('test-access-token');

        $this->assertCount(2, $accounts);
        $this->assertEquals('Account 1', $accounts[0]['name']);
        $this->assertEquals(12345, $accounts[0]['id']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'id.getharvest.com/api/v2/accounts') &&
                   $request->hasHeader('Authorization', 'Bearer test-access-token');
        });
    }

    /** @test */
    public function it_returns_empty_array_when_no_accounts()
    {
        Http::fake([
            'id.getharvest.com/api/v2/accounts' => Http::response([
                'accounts' => null,
            ], 200),
        ]);

        $accounts = $this->service->getAccounts('test-token');

        $this->assertEquals([], $accounts);
    }

    /** @test */
    public function it_throws_exception_on_failed_accounts_fetch()
    {
        Http::fake([
            'id.getharvest.com/api/v2/accounts' => Http::response([
                'error' => 'unauthorized',
            ], 401),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get accounts');

        $this->service->getAccounts('invalid-token');
    }

    /** @test */
    public function it_detects_token_expiration()
    {
        $expired = HarvestCredential::factory()->create([
            'user_id' => $this->user->id,
            'expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($expired->isExpired());

        $valid = HarvestCredential::factory()->create([
            'user_id' => User::factory()->create()->id,
            'expires_at' => now()->addHours(12),
        ]);

        $this->assertFalse($valid->isExpired());
    }

    /** @test */
    public function it_calculates_expiration_correctly_when_storing()
    {
        $tokenData = [
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'expires_in' => 64800, // 18 hours
        ];

        $accountData = ['id' => 123, 'name' => 'Test'];

        $credential = $this->service->storeCredentials($this->user, $tokenData, $accountData);

        $this->assertTrue($credential->expires_at->isBetween(
            now()->addSeconds(64795),
            now()->addSeconds(64805)
        ));
    }

    /** @test */
    public function it_uses_correct_authorization_header_format()
    {
        Http::fake([
            'id.getharvest.com/api/v2/accounts' => Http::response([
                'accounts' => [],
            ], 200),
        ]);

        $this->service->getAccounts('my-access-token');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer my-access-token');
        });
    }
}
