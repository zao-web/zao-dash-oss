<?php

namespace Tests\Unit\Services\LinkedIn;

use App\Models\LinkedInCredential;
use App\Models\User;
use App\Services\LinkedIn\LinkedInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LinkedInServiceTest extends TestCase
{
    use RefreshDatabase;

    protected LinkedInService $service;

    protected LinkedInCredential $credential;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.linkedin.client_id' => 'test-client-id',
            'services.linkedin.client_secret' => 'test-client-secret',
        ]);

        $this->service = new LinkedInService;

        $user = User::factory()->create();
        $this->credential = LinkedInCredential::factory()->create([
            'user_id' => $user->id,
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'linkedin_id' => '12345',
            'token_expires_at' => now()->addHours(2),
        ]);
    }

    /** @test */
    public function it_generates_auth_url()
    {
        $url = $this->service->getAuthUrl('https://example.com/callback');

        $this->assertStringContainsString('linkedin.com/oauth/v2/authorization', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString('redirect_uri=https%3A%2F%2Fexample.com%2Fcallback', $url);
        $this->assertStringContainsString('scope=openid+profile+email+w_member_social', $url);
    }

    /** @test */
    public function it_exchanges_code_for_token()
    {
        Http::fake([
            'linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 5184000,
            ], 200),
        ]);

        $result = $this->service->exchangeCodeForToken('auth-code', 'https://example.com/callback');

        $this->assertEquals('new-access-token', $result['access_token']);
        $this->assertEquals('new-refresh-token', $result['refresh_token']);
    }

    /** @test */
    public function it_throws_exception_on_failed_token_exchange()
    {
        Http::fake([
            'linkedin.com/oauth/v2/accessToken' => Http::response([
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
            'linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'refreshed-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 5184000,
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
            'linkedin.com/oauth/v2/accessToken' => Http::response([
                'error' => 'invalid_grant',
            ], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to refresh token');

        $this->service->refreshToken($this->credential);
    }

    /** @test */
    public function it_gets_user_profile()
    {
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response([
                'sub' => '12345',
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'picture' => 'https://example.com/photo.jpg',
            ], 200),
        ]);

        $profile = $this->service->getProfile($this->credential);

        $this->assertEquals('12345', $profile['sub']);
        $this->assertEquals('John Doe', $profile['name']);
        $this->assertEquals('john@example.com', $profile['email']);
    }

    /** @test */
    public function it_throws_exception_on_failed_profile_fetch()
    {
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response('Unauthorized', 401),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get profile');

        $this->service->getProfile($this->credential);
    }

    /** @test */
    public function it_creates_text_post()
    {
        Http::fake([
            'api.linkedin.com/v2/ugcPosts' => Http::response([
                'id' => 'urn:li:share:123456',
            ], 201),
        ]);

        $result = $this->service->createTextPost($this->credential, 'This is a test post');

        $this->assertEquals('urn:li:share:123456', $result['id']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->hasHeader('X-Restli-Protocol-Version', '2.0.0') &&
                   $body['specificContent']['com.linkedin.ugc.ShareContent']['shareCommentary']['text'] === 'This is a test post' &&
                   $body['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] === 'NONE';
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_post_creation()
    {
        Http::fake([
            'api.linkedin.com/v2/ugcPosts' => Http::response([
                'message' => 'Invalid request',
            ], 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to create post');

        $this->service->createTextPost($this->credential, 'Test');
    }

    /** @test */
    public function it_creates_article_post()
    {
        Http::fake([
            'api.linkedin.com/v2/ugcPosts' => Http::response([
                'id' => 'urn:li:share:789',
            ], 201),
        ]);

        $result = $this->service->createArticlePost(
            $this->credential,
            'Check out this article',
            'https://example.com/article',
            'Great Article',
            'This is a great read'
        );

        $this->assertEquals('urn:li:share:789', $result['id']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['specificContent']['com.linkedin.ugc.ShareContent']['shareMediaCategory'] === 'ARTICLE' &&
                   $body['specificContent']['com.linkedin.ugc.ShareContent']['media'][0]['originalUrl'] === 'https://example.com/article';
        });
    }

    /** @test */
    public function it_creates_organization_post()
    {
        $this->credential->update(['organization_id' => '67890']);

        Http::fake([
            'api.linkedin.com/v2/ugcPosts' => Http::response([
                'id' => 'urn:li:share:999',
            ], 201),
        ]);

        $result = $this->service->createOrganizationPost($this->credential, 'Company update');

        $this->assertEquals('urn:li:share:999', $result['id']);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['author'] === 'urn:li:organization:67890';
        });
    }

    /** @test */
    public function it_throws_exception_when_no_organization_access()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No organization access configured');

        $this->service->createOrganizationPost($this->credential, 'Test');
    }

    /** @test */
    public function it_gets_organizations()
    {
        Http::fake([
            'api.linkedin.com/v2/organizationAcls*' => Http::response([
                'elements' => [
                    ['organization' => 'urn:li:organization:123'],
                    ['organization' => 'urn:li:organization:456'],
                ],
            ], 200),
        ]);

        $organizations = $this->service->getOrganizations($this->credential);

        $this->assertCount(2, $organizations);
    }

    /** @test */
    public function it_returns_empty_array_on_failed_organizations_fetch()
    {
        Http::fake([
            'api.linkedin.com/v2/organizationAcls*' => Http::response('Error', 500),
        ]);

        $organizations = $this->service->getOrganizations($this->credential);

        $this->assertEmpty($organizations);
    }

    /** @test */
    public function it_refreshes_token_when_expired()
    {
        $this->credential->update(['token_expires_at' => now()->subHour()]);

        Http::fake([
            'linkedin.com/oauth/v2/accessToken' => Http::response([
                'access_token' => 'new-token',
                'refresh_token' => 'new-refresh',
                'expires_in' => 5184000,
            ], 200),
            'api.linkedin.com/v2/userinfo' => Http::response([
                'sub' => '12345',
                'name' => 'John Doe',
            ], 200),
        ]);

        $this->service->getProfile($this->credential);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'accessToken');
        });
    }

    /** @test */
    public function it_sends_authorization_header()
    {
        Http::fake([
            'api.linkedin.com/*' => Http::response(['data' => 'test'], 200),
        ]);

        $this->service->getProfile($this->credential);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-access-token');
        });
    }
}
