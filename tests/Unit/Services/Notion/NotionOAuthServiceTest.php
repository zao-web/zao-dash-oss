<?php

namespace Tests\Unit\Services\Notion;

use App\Services\Notion\NotionOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotionOAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected NotionOAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.notion.client_id' => 'test-client-id',
            'services.notion.client_secret' => 'test-client-secret',
            'services.notion.redirect_uri' => 'https://example.com/callback',
        ]);

        $this->service = new NotionOAuthService;
    }

    /** @test */
    public function it_generates_authorization_url()
    {
        $url = $this->service->getAuthorizationUrl('test-state-123');

        $this->assertStringContainsString('api.notion.com/v1/oauth/authorize', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fexample.com%2Fcallback', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('owner=user', $url);
        $this->assertStringContainsString('state=test-state-123', $url);
    }

    /** @test */
    public function it_exchanges_code_for_token()
    {
        Http::fake([
            'api.notion.com/v1/oauth/token' => Http::response([
                'access_token' => 'secret_test_token',
                'workspace_id' => 'workspace-123',
                'workspace_name' => 'Test Workspace',
                'workspace_icon' => '🚀',
                'bot_id' => 'bot-456',
                'owner' => [
                    'type' => 'user',
                    'user' => ['id' => 'user-789'],
                ],
            ], 200),
        ]);

        $result = $this->service->exchangeCodeForToken('auth-code-123');

        $this->assertEquals('secret_test_token', $result['access_token']);
        $this->assertEquals('workspace-123', $result['workspace_id']);
        $this->assertEquals('Test Workspace', $result['workspace_name']);
        $this->assertEquals('bot-456', $result['bot_id']);
    }

    /** @test */
    public function it_throws_exception_on_failed_token_exchange()
    {
        Http::fake([
            'api.notion.com/v1/oauth/token' => Http::response([
                'error' => 'invalid_grant',
            ], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to exchange code for token');

        $this->service->exchangeCodeForToken('invalid-code');
    }

    /** @test */
    public function it_uses_basic_auth_for_token_exchange()
    {
        Http::fake([
            'api.notion.com/v1/oauth/token' => Http::response([
                'access_token' => 'token',
                'workspace_id' => 'ws-1',
                'workspace_name' => 'WS',
                'bot_id' => 'bot-1',
                'owner' => ['type' => 'user'],
            ], 200),
        ]);

        $this->service->exchangeCodeForToken('code');

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization') &&
                   str_contains($request->header('Authorization')[0], 'Basic');
        });
    }

    /** @test */
    public function it_stores_connection()
    {
        $user = \App\Models\User::factory()->create();

        $tokenData = [
            'access_token' => 'secret_token',
            'workspace_id' => 'workspace-123',
            'workspace_name' => 'My Workspace',
            'workspace_icon' => '📚',
            'bot_id' => 'bot-456',
            'owner' => [
                'type' => 'user',
                'user' => ['id' => 'user-789'],
            ],
            'duplicated_template_id' => 'template-123',
            'request_id' => 'request-456',
        ];

        $connection = $this->service->storeConnection($tokenData, $user->id);

        $this->assertDatabaseHas('notion_connections', [
            'workspace_id' => 'workspace-123',
            'workspace_name' => 'My Workspace',
            'workspace_icon' => '📚',
            'bot_id' => 'bot-456',
            'owner_type' => 'user',
            'owner_id' => 'user-789',
            'user_id' => $user->id,
        ]);

        // Check encrypted token separately
        $this->assertEquals('secret_token', $connection->access_token);
    }

    /** @test */
    public function it_updates_existing_connection()
    {
        $initialData = [
            'access_token' => 'old_token',
            'workspace_id' => 'workspace-123',
            'workspace_name' => 'Old Name',
            'bot_id' => 'bot-old',
            'owner' => ['type' => 'user'],
        ];

        $this->service->storeConnection($initialData);

        $updatedData = [
            'access_token' => 'new_token',
            'workspace_id' => 'workspace-123',
            'workspace_name' => 'New Name',
            'bot_id' => 'bot-new',
            'owner' => ['type' => 'user'],
        ];

        $connection = $this->service->storeConnection($updatedData);

        $this->assertEquals('new_token', $connection->access_token);
        $this->assertEquals('New Name', $connection->workspace_name);
        $this->assertEquals(1, \App\Models\NotionConnection::count());
    }

    /** @test */
    public function it_stores_connection_without_optional_fields()
    {
        $tokenData = [
            'access_token' => 'token',
            'workspace_id' => 'ws-123',
            'workspace_name' => 'Workspace',
            'bot_id' => 'bot-123',
            'owner' => ['type' => 'workspace'],
        ];

        $connection = $this->service->storeConnection($tokenData);

        $this->assertDatabaseHas('notion_connections', [
            'workspace_id' => 'ws-123',
            'owner_type' => 'workspace',
        ]);
        $this->assertNull($connection->owner_id);
        $this->assertNull($connection->workspace_icon);
    }
}
