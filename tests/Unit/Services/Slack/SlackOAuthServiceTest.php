<?php

namespace Tests\Unit\Services\Slack;

use App\Models\SlackWorkspace;
use App\Services\Slack\SlackOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlackOAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SlackOAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.slack.client_id' => 'test-slack-client-id',
            'services.slack.client_secret' => 'test-slack-client-secret',
            'services.slack.redirect_uri' => 'https://example.com/auth/slack/callback',
        ]);

        $this->service = new SlackOAuthService;
    }

    /** @test */
    public function it_generates_authorization_url_with_correct_scopes()
    {
        $url = $this->service->getAuthUrl('test-state-slack');

        $this->assertStringContainsString('https://slack.com/oauth/v2/authorize', $url);
        $this->assertStringContainsString('client_id='.config('services.slack.client_id'), $url);
        $this->assertStringContainsString('redirect_uri='.urlencode(config('services.slack.redirect_uri')), $url);
        $this->assertStringContainsString('state=test-state-slack', $url);
        $this->assertStringContainsString('scope=', $url);

        // Check for specific scopes
        $this->assertStringContainsString('channels%3Ahistory', $url);
        $this->assertStringContainsString('channels%3Aread', $url);
        $this->assertStringContainsString('users%3Aread', $url);
        $this->assertStringContainsString('chat%3Awrite', $url);
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
    public function it_uses_comma_separated_scopes()
    {
        $url = $this->service->getAuthUrl('test');

        // Slack uses comma-separated scopes, not space-separated
        $this->assertStringContainsString('%2C', $url); // URL-encoded comma
    }

    /** @test */
    public function it_exchanges_code_for_tokens_successfully()
    {
        Http::fake([
            'slack.com/api/oauth.v2.access' => Http::response([
                'ok' => true,
                'access_token' => 'xoxb-slack-bot-token',
                'token_type' => 'bot',
                'scope' => 'channels:history,channels:read,chat:write',
                'bot_user_id' => 'U123BOTID',
                'app_id' => 'A123APPID',
                'team' => [
                    'id' => 'T123TEAMID',
                    'name' => 'Test Workspace',
                ],
                'authed_user' => [
                    'id' => 'U123USERID',
                ],
            ], 200),
        ]);

        $tokens = $this->service->exchangeCodeForTokens('slack-auth-code');

        $this->assertTrue($tokens['ok']);
        $this->assertEquals('xoxb-slack-bot-token', $tokens['access_token']);
        $this->assertEquals('U123BOTID', $tokens['bot_user_id']);
        $this->assertEquals('Test Workspace', $tokens['team']['name']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'slack.com/api/oauth.v2.access') &&
                   $request['code'] === 'slack-auth-code' &&
                   $request['client_id'] === config('services.slack.client_id') &&
                   $request['client_secret'] === config('services.slack.client_secret') &&
                   $request['redirect_uri'] === config('services.slack.redirect_uri');
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_code_exchange()
    {
        Http::fake([
            'slack.com/api/oauth.v2.access' => Http::response([
                'ok' => false,
                'error' => 'invalid_code',
            ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to exchange code for tokens');

        $this->service->exchangeCodeForTokens('invalid-code');
    }

    /** @test */
    public function it_throws_exception_on_http_error()
    {
        Http::fake([
            'slack.com/api/oauth.v2.access' => Http::response([], 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to exchange code for tokens');

        $this->service->exchangeCodeForTokens('code');
    }

    /** @test */
    public function it_stores_workspace_successfully()
    {
        $tokenData = [
            'ok' => true,
            'access_token' => 'xoxb-workspace-token',
            'bot_user_id' => 'U123BOT',
            'team' => [
                'id' => 'T123TEAM',
                'name' => 'My Workspace',
            ],
        ];

        $workspace = $this->service->storeWorkspace($tokenData);

        $this->assertDatabaseHas('slack_workspaces', [
            'workspace_id' => 'T123TEAM',
            'workspace_name' => 'My Workspace',
            'bot_user_id' => 'U123BOT',
        ]);

        $this->assertEquals('xoxb-workspace-token', $workspace->access_token);
    }

    /** @test */
    public function it_handles_missing_team_data_when_storing_workspace()
    {
        $tokenData = [
            'ok' => true,
            'access_token' => 'xoxb-token',
            'team' => [],
        ];

        $workspace = $this->service->storeWorkspace($tokenData);

        $this->assertDatabaseHas('slack_workspaces', [
            'workspace_name' => 'Unknown',
        ]);
    }

    /** @test */
    public function it_updates_existing_workspace_when_storing()
    {
        $existing = SlackWorkspace::factory()->create([
            'workspace_id' => 'T123TEAM',
            'workspace_name' => 'Old Name',
            'bot_user_id' => 'U111OLD',
        ]);

        $tokenData = [
            'ok' => true,
            'access_token' => 'xoxb-new-token',
            'bot_user_id' => 'U222NEW',
            'team' => [
                'id' => 'T123TEAM',
                'name' => 'New Name',
            ],
        ];

        $workspace = $this->service->storeWorkspace($tokenData);

        $this->assertEquals($existing->id, $workspace->id);
        $this->assertEquals('New Name', $workspace->workspace_name);
        $this->assertEquals('U222NEW', $workspace->bot_user_id);
        $this->assertEquals(1, SlackWorkspace::count());
    }

    /** @test */
    public function it_stores_access_token_encrypted()
    {
        $tokenData = [
            'ok' => true,
            'access_token' => 'xoxb-plain-token',
            'team' => [
                'id' => 'T123',
                'name' => 'Test',
            ],
        ];

        $workspace = $this->service->storeWorkspace($tokenData);

        $rawToken = \DB::table('slack_workspaces')
            ->where('id', $workspace->id)
            ->value('access_token');

        $this->assertNotEquals('xoxb-plain-token', $rawToken);
        $this->assertEquals('xoxb-plain-token', $workspace->access_token);
    }

    /** @test */
    public function it_revokes_access_successfully()
    {
        $workspace = SlackWorkspace::factory()->create([
            'access_token' => 'xoxb-token-to-revoke',
        ]);

        Http::fake([
            'slack.com/api/auth.revoke' => Http::response([
                'ok' => true,
                'revoked' => true,
            ], 200),
        ]);

        $result = $this->service->revokeAccess($workspace);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('slack_workspaces', [
            'id' => $workspace->id,
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'auth.revoke') &&
                   $request->hasHeader('Authorization', 'Bearer xoxb-token-to-revoke');
        });
    }

    /** @test */
    public function it_handles_failed_revocation()
    {
        $workspace = SlackWorkspace::factory()->create([
            'access_token' => 'xoxb-token',
        ]);

        Http::fake([
            'slack.com/api/auth.revoke' => Http::response([
                'ok' => false,
            ], 400),
        ]);

        $result = $this->service->revokeAccess($workspace);

        $this->assertFalse($result);
        // Workspace should still be deleted even if revocation fails
        $this->assertDatabaseMissing('slack_workspaces', [
            'id' => $workspace->id,
        ]);
    }

    /** @test */
    public function it_tests_connection_successfully()
    {
        $workspace = SlackWorkspace::factory()->create([
            'access_token' => 'xoxb-valid-token',
        ]);

        Http::fake([
            'slack.com/api/auth.test' => Http::response([
                'ok' => true,
                'url' => 'https://test.slack.com/',
                'team' => 'Test Workspace',
                'user' => 'bot',
                'team_id' => 'T123',
                'user_id' => 'U123',
            ], 200),
        ]);

        $result = $this->service->testConnection($workspace);

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'auth.test') &&
                   $request->hasHeader('Authorization', 'Bearer xoxb-valid-token');
        });
    }

    /** @test */
    public function it_handles_failed_connection_test()
    {
        $workspace = SlackWorkspace::factory()->create([
            'access_token' => 'xoxb-invalid-token',
        ]);

        Http::fake([
            'slack.com/api/auth.test' => Http::response([
                'ok' => false,
                'error' => 'invalid_auth',
            ], 200),
        ]);

        $result = $this->service->testConnection($workspace);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_handles_http_error_in_connection_test()
    {
        $workspace = SlackWorkspace::factory()->create([
            'access_token' => 'xoxb-token',
        ]);

        Http::fake([
            'slack.com/api/auth.test' => Http::response([], 500),
        ]);

        $result = $this->service->testConnection($workspace);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_uses_form_encoded_request_for_token_exchange()
    {
        Http::fake([
            'slack.com/api/oauth.v2.access' => Http::response([
                'ok' => true,
                'access_token' => 'token',
                'team' => ['id' => 'T123', 'name' => 'Test'],
            ], 200),
        ]);

        $this->service->exchangeCodeForTokens('code');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded');
        });
    }

    /** @test */
    public function it_includes_all_required_scopes()
    {
        $url = $this->service->getAuthUrl('test');

        $requiredScopes = [
            'channels:history',
            'channels:read',
            'groups:history',
            'groups:read',
            'im:history',
            'im:read',
            'mpim:history',
            'mpim:read',
            'users:read',
            'users:read.email',
            'chat:write',
            'commands',
            'reactions:read',
        ];

        foreach ($requiredScopes as $scope) {
            $this->assertStringContainsString(urlencode($scope), $url);
        }
    }

    /** @test */
    public function it_handles_missing_bot_user_id()
    {
        $tokenData = [
            'ok' => true,
            'access_token' => 'xoxb-token',
            'team' => [
                'id' => 'T123',
                'name' => 'Test',
            ],
        ];

        $workspace = $this->service->storeWorkspace($tokenData);

        $this->assertNull($workspace->bot_user_id);
    }
}
