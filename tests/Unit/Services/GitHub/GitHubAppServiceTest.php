<?php

namespace Tests\Unit\Services\GitHub;

use App\Models\GitHubInstallation;
use App\Models\GitHubRepo;
use App\Services\GitHub\GitHubAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GitHubAppServiceTest extends TestCase
{
    use RefreshDatabase;

    protected GitHubAppService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GitHubAppService;

        // Set test config values
        config([
            'services.github.app_id' => '123456',
            'services.github.private_key' => $this->generateTestPrivateKey(),
            'services.github.app_slug' => 'test-app',
        ]);
    }

    /** @test */
    public function it_generates_jwt_token_successfully()
    {
        $jwt = $this->service->generateAppJwt();

        $this->assertNotEmpty($jwt);
        $this->assertIsString($jwt);
        $this->assertCount(3, explode('.', $jwt)); // JWT has 3 parts
    }

    /** @test */
    public function it_generates_jwt_with_correct_claims()
    {
        $jwt = $this->service->generateAppJwt();
        $parts = explode('.', $jwt);
        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);

        $this->assertEquals('123456', $payload['iss']);
        $this->assertArrayHasKey('iat', $payload);
        $this->assertArrayHasKey('exp', $payload);
        $this->assertLessThan($payload['exp'], $payload['iat']);
    }

    /** @test */
    public function it_gets_installation_token_successfully()
    {
        $installation = GitHubInstallation::factory()->create([
            'installation_id' => 12345,
            'access_token' => null,
            'token_expires_at' => null,
        ]);

        Http::fake([
            'api.github.com/app/installations/12345/access_tokens' => Http::response([
                'token' => 'ghs_installation_token',
                'expires_at' => '2025-12-14T12:00:00Z',
                'permissions' => [
                    'contents' => 'read',
                    'metadata' => 'read',
                ],
            ], 201),
        ]);

        $token = $this->service->getInstallationToken($installation);

        $this->assertEquals('ghs_installation_token', $token);
        $this->assertEquals('ghs_installation_token', $installation->fresh()->access_token);
        $this->assertNotNull($installation->fresh()->token_expires_at);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'access_tokens') &&
                   $request->hasHeader('Authorization') &&
                   str_contains($request->header('Authorization')[0], 'Bearer') &&
                   $request->hasHeader('Accept', 'application/vnd.github+json');
        });
    }

    /** @test */
    public function it_returns_cached_token_when_not_expired()
    {
        $installation = GitHubInstallation::factory()->create([
            'installation_id' => 12345,
            'access_token' => 'ghs_cached_token',
            'token_expires_at' => now()->addHour(),
        ]);

        Http::fake();

        $token = $this->service->getInstallationToken($installation);

        $this->assertEquals('ghs_cached_token', $token);
        Http::assertNothingSent();
    }

    /** @test */
    public function it_refreshes_expired_installation_token()
    {
        $installation = GitHubInstallation::factory()->create([
            'installation_id' => 12345,
            'access_token' => 'ghs_expired_token',
            'token_expires_at' => now()->subHour(),
        ]);

        Http::fake([
            'api.github.com/app/installations/12345/access_tokens' => Http::response([
                'token' => 'ghs_new_token',
                'expires_at' => '2025-12-14T12:00:00Z',
                'permissions' => [],
            ], 201),
        ]);

        $token = $this->service->getInstallationToken($installation);

        $this->assertEquals('ghs_new_token', $token);
        $this->assertEquals('ghs_new_token', $installation->fresh()->access_token);
    }

    /** @test */
    public function it_throws_exception_on_failed_installation_token_request()
    {
        $installation = GitHubInstallation::factory()->create([
            'installation_id' => 12345,
            'token_expires_at' => null,
        ]);

        Http::fake([
            'api.github.com/app/installations/12345/access_tokens' => Http::response([
                'message' => 'Not Found',
            ], 404),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get installation token');

        $this->service->getInstallationToken($installation);
    }

    /** @test */
    public function it_stores_token_encrypted()
    {
        $installation = GitHubInstallation::factory()->create([
            'installation_id' => 12345,
        ]);

        Http::fake([
            'api.github.com/app/installations/12345/access_tokens' => Http::response([
                'token' => 'ghs_plain_token',
                'expires_at' => '2025-12-14T12:00:00Z',
            ], 201),
        ]);

        $this->service->getInstallationToken($installation);

        $rawToken = \DB::table('github_installations')
            ->where('id', $installation->id)
            ->value('access_token');

        $this->assertNotEquals('ghs_plain_token', $rawToken);
        $this->assertEquals('ghs_plain_token', $installation->fresh()->access_token);
    }

    /** @test */
    public function it_lists_installations_successfully()
    {
        Http::fake([
            'api.github.com/app/installations' => Http::response([
                [
                    'id' => 111,
                    'account' => [
                        'login' => 'user1',
                        'type' => 'User',
                    ],
                ],
                [
                    'id' => 222,
                    'account' => [
                        'login' => 'org1',
                        'type' => 'Organization',
                    ],
                ],
            ], 200),
        ]);

        $installations = $this->service->listInstallations();

        $this->assertCount(2, $installations);
        $this->assertEquals(111, $installations[0]['id']);
        $this->assertEquals('user1', $installations[0]['account']['login']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'app/installations') &&
                   $request->hasHeader('Authorization') &&
                   $request->hasHeader('Accept', 'application/vnd.github+json');
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_installations_list()
    {
        Http::fake([
            'api.github.com/app/installations' => Http::response([], 401),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to list installations');

        $this->service->listInstallations();
    }

    /** @test */
    public function it_stores_installation_successfully()
    {
        $installationData = [
            'id' => 12345,
            'account' => [
                'login' => 'test-user',
                'type' => 'User',
                'id' => 67890,
            ],
            'repository_selection' => 'selected',
            'permissions' => [
                'contents' => 'read',
                'metadata' => 'read',
            ],
        ];

        $installation = $this->service->storeInstallation($installationData);

        $this->assertDatabaseHas('github_installations', [
            'installation_id' => 12345,
            'account_login' => 'test-user',
            'account_type' => 'User',
            'account_id' => 67890,
            'repos_access' => 'selected',
        ]);

        $this->assertIsArray($installation->permissions);
        $this->assertEquals('read', $installation->permissions['contents']);
    }

    /** @test */
    public function it_updates_existing_installation_when_storing()
    {
        $existing = GitHubInstallation::factory()->create([
            'installation_id' => 12345,
            'account_login' => 'old-user',
            'repos_access' => 'all',
        ]);

        $installationData = [
            'id' => 12345,
            'account' => [
                'login' => 'new-user',
                'type' => 'User',
                'id' => 99999,
            ],
            'repository_selection' => 'selected',
        ];

        $installation = $this->service->storeInstallation($installationData);

        $this->assertEquals($existing->id, $installation->id);
        $this->assertEquals('new-user', $installation->account_login);
        $this->assertEquals('selected', $installation->repos_access);
        $this->assertEquals(1, GitHubInstallation::count());
    }

    /** @test */
    public function it_syncs_repos_successfully()
    {
        $installation = GitHubInstallation::factory()->create([
            'installation_id' => 12345,
            'access_token' => 'ghs_token',
            'token_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'api.github.com/installation/repositories*' => Http::response([
                'total_count' => 2,
                'repositories' => [
                    [
                        'id' => 111,
                        'name' => 'repo1',
                        'full_name' => 'user/repo1',
                        'owner' => ['login' => 'user'],
                        'private' => false,
                        'default_branch' => 'main',
                    ],
                    [
                        'id' => 222,
                        'name' => 'repo2',
                        'full_name' => 'user/repo2',
                        'owner' => ['login' => 'user'],
                        'private' => true,
                        'default_branch' => 'master',
                    ],
                ],
            ], 200),
        ]);

        $count = $this->service->syncRepos($installation);

        $this->assertEquals(2, $count);
        $this->assertDatabaseHas('git_hub_repos', [
            'installation_id' => $installation->id,
            'repo_id' => 111,
            'name' => 'repo1',
            'full_name' => 'user/repo1',
            'is_private' => false,
        ]);
        $this->assertDatabaseHas('git_hub_repos', [
            'installation_id' => $installation->id,
            'repo_id' => 222,
            'name' => 'repo2',
            'is_private' => true,
        ]);
    }

    /** @test */
    public function it_handles_pagination_when_syncing_repos()
    {
        $installation = GitHubInstallation::factory()->create([
            'installation_id' => 12345,
            'access_token' => 'ghs_token',
            'token_expires_at' => now()->addHour(),
        ]);

        Http::fake([
            'api.github.com/installation/repositories*page=2*' => Http::response([
                'repositories' => [], // Empty second page
            ], 200),
            'api.github.com/installation/repositories*' => Http::response([
                'repositories' => array_fill(0, 100, [
                    'id' => 1,
                    'name' => 'repo',
                    'full_name' => 'user/repo',
                    'owner' => ['login' => 'user'],
                    'private' => false,
                    'default_branch' => 'main',
                ]),
            ], 200),
        ]);

        $this->service->syncRepos($installation);

        // Should make at least 2 requests (page 1 with 100 items, page 2 empty)
        Http::assertSentCount(2);
    }

    /** @test */
    public function it_updates_existing_repos_when_syncing()
    {
        $installation = GitHubInstallation::factory()->create([
            'installation_id' => 12345,
            'access_token' => 'ghs_token',
            'token_expires_at' => now()->addHour(),
        ]);

        $existingRepo = GitHubRepo::factory()->create([
            'installation_id' => $installation->id,
            'repo_id' => 111,
            'name' => 'old-name',
        ]);

        Http::fake([
            'api.github.com/installation/repositories*' => Http::response([
                'repositories' => [
                    [
                        'id' => 111,
                        'name' => 'new-name',
                        'full_name' => 'user/new-name',
                        'owner' => ['login' => 'user'],
                        'private' => false,
                        'default_branch' => 'main',
                    ],
                ],
            ], 200),
        ]);

        $this->service->syncRepos($installation);

        $this->assertEquals(1, GitHubRepo::count());
        $this->assertEquals('new-name', $existingRepo->fresh()->name);
    }

    /** @test */
    public function it_generates_installation_url()
    {
        $url = $this->service->getInstallationUrl();

        $this->assertEquals('https://github.com/apps/test-app/installations/new', $url);
    }

    /** @test */
    public function it_handles_installation_created_webhook()
    {
        Http::fake([
            'api.github.com/app/installations/12345/access_tokens' => Http::response([
                'token' => 'ghs_token',
                'expires_at' => '2025-12-14T12:00:00Z',
            ], 201),
            'api.github.com/installation/repositories*' => Http::response([
                'repositories' => [
                    [
                        'id' => 111,
                        'name' => 'repo',
                        'full_name' => 'user/repo',
                        'owner' => ['login' => 'user'],
                        'private' => false,
                        'default_branch' => 'main',
                    ],
                ],
            ], 200),
        ]);

        $installationData = [
            'id' => 12345,
            'account' => [
                'login' => 'test-user',
                'type' => 'User',
                'id' => 67890,
            ],
        ];

        $this->service->handleInstallationWebhook('created', $installationData);

        $this->assertDatabaseHas('github_installations', [
            'installation_id' => 12345,
            'account_login' => 'test-user',
        ]);
        $this->assertDatabaseHas('git_hub_repos', [
            'repo_id' => 111,
        ]);
    }

    /** @test */
    public function it_handles_installation_deleted_webhook()
    {
        $installation = GitHubInstallation::factory()->create([
            'installation_id' => 12345,
        ]);

        $this->service->handleInstallationWebhook('deleted', ['id' => 12345]);

        $this->assertDatabaseMissing('github_installations', [
            'installation_id' => 12345,
        ]);
    }

    /** @test */
    public function it_handles_new_permissions_accepted_webhook()
    {
        Http::fake([
            'api.github.com/app/installations/12345/access_tokens' => Http::response([
                'token' => 'ghs_token',
                'expires_at' => '2025-12-14T12:00:00Z',
            ], 201),
            'api.github.com/installation/repositories*' => Http::response([
                'repositories' => [],
            ], 200),
        ]);

        $installationData = [
            'id' => 12345,
            'account' => [
                'login' => 'test-user',
                'type' => 'User',
                'id' => 67890,
            ],
        ];

        $this->service->handleInstallationWebhook('new_permissions_accepted', $installationData);

        $this->assertDatabaseHas('github_installations', [
            'installation_id' => 12345,
        ]);
    }

    /** @test */
    public function it_detects_token_expiration()
    {
        $expired = GitHubInstallation::factory()->create([
            'token_expires_at' => now()->subMinute(),
        ]);

        $this->assertTrue($expired->tokenIsExpired());

        $valid = GitHubInstallation::factory()->create([
            'token_expires_at' => now()->addHour(),
        ]);

        $this->assertFalse($valid->tokenIsExpired());

        $null = GitHubInstallation::factory()->create([
            'token_expires_at' => null,
        ]);

        $this->assertTrue($null->tokenIsExpired());
    }

    /** @test */
    public function it_detects_organization_installations()
    {
        $org = GitHubInstallation::factory()->create([
            'account_type' => 'Organization',
        ]);

        $this->assertTrue($org->isOrgInstallation());

        $user = GitHubInstallation::factory()->create([
            'account_type' => 'User',
        ]);

        $this->assertFalse($user->isOrgInstallation());
    }

    /**
     * Generate a test RSA private key for JWT signing
     */
    protected function generateTestPrivateKey(): string
    {
        $config = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $res = openssl_pkey_new($config);
        openssl_pkey_export($res, $privateKey);

        return $privateKey;
    }
}
