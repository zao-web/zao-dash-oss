<?php

namespace Tests\Unit\Services\WordPress;

use App\Models\WordPressSite;
use App\Services\WordPress\WordPressMcpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordPressMcpServiceTest extends TestCase
{
    use RefreshDatabase;

    protected WordPressMcpService $service;

    protected WordPressSite $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WordPressMcpService;
        $this->site = WordPressSite::factory()->create([
            'url' => 'https://example.com',
            'username' => 'test',
            'application_password' => 'test',
            'mcp_enabled' => true,
            'rest_url' => 'https://example.com/wp-json/mcp/v1',
        ]);
    }

    /** @test */
    public function it_tests_connection_successfully()
    {
        Http::fake([
            'example.com/wp-json/*' => Http::response(['namespace' => 'wp/v2'], 200),
        ]);

        $result = $this->service->testConnection($this->site);

        $this->assertTrue($result);
    }

    /** @test */
    public function it_returns_false_on_failed_connection()
    {
        Http::fake([
            'example.com/wp-json/*' => Http::response('Not found', 404),
        ]);

        $result = $this->service->testConnection($this->site);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_returns_false_on_connection_exception()
    {
        Http::fake(function () {
            throw new \Exception('Connection timeout');
        });

        $result = $this->service->testConnection($this->site);

        $this->assertFalse($result);
    }

    /** @test */
    public function it_discovers_mcp_capabilities()
    {
        Http::fake([
            'example.com/wp-json/mcp/*' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => [
                    'tools' => [
                        ['name' => 'create_post', 'description' => 'Create a post'],
                        ['name' => 'update_post', 'description' => 'Update a post'],
                    ],
                ],
            ], 200),
        ]);

        $capabilities = $this->service->discoverCapabilities($this->site);

        $this->assertCount(2, $capabilities);
        $this->assertEquals('create_post', $capabilities[0]['name']);
        $this->assertEquals('update_post', $capabilities[1]['name']);

        $this->site->refresh();
        $this->assertNotNull($this->site->last_connected_at);
        $this->assertCount(2, $this->site->capabilities);
    }

    /** @test */
    public function it_returns_empty_array_when_mcp_disabled()
    {
        $this->site->update(['mcp_enabled' => false]);

        $capabilities = $this->service->discoverCapabilities($this->site);

        $this->assertEmpty($capabilities);
    }

    /** @test */
    public function it_returns_empty_array_on_failed_capability_discovery()
    {
        Http::fake([
            'example.com/wp-json/mcp/*' => Http::response('Error', 500),
        ]);

        $capabilities = $this->service->discoverCapabilities($this->site);

        $this->assertEmpty($capabilities);
    }

    /** @test */
    public function it_invokes_mcp_tool()
    {
        Http::fake([
            'example.com/wp-json/mcp/*' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['post_id' => 123, 'status' => 'published'],
            ], 200),
        ]);

        $result = $this->service->invokeTool($this->site, 'create_post', [
            'title' => 'Test Post',
            'content' => 'Test content',
        ]);

        $this->assertEquals(123, $result['post_id']);
        $this->assertEquals('published', $result['status']);
    }

    /** @test */
    public function it_throws_exception_on_failed_tool_invocation()
    {
        Http::fake([
            'example.com/wp-json/mcp/*' => Http::response('Server error', 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('MCP tool invocation failed');

        $this->service->invokeTool($this->site, 'create_post', ['title' => 'Test']);
    }

    /** @test */
    public function it_throws_exception_on_mcp_error_response()
    {
        Http::fake([
            'example.com/wp-json/mcp/*' => Http::response([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['message' => 'Invalid parameters'],
            ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('MCP error: Invalid parameters');

        $this->service->invokeTool($this->site, 'create_post', []);
    }

    /** @test */
    public function it_gets_posts_via_rest_api()
    {
        Http::fake([
            'example.com/wp-json/wp/v2/posts*' => Http::response([
                [
                    'id' => 1,
                    'title' => ['rendered' => 'Post 1'],
                    'status' => 'publish',
                ],
                [
                    'id' => 2,
                    'title' => ['rendered' => 'Post 2'],
                    'status' => 'draft',
                ],
            ], 200),
        ]);

        $posts = $this->service->getPosts($this->site);

        $this->assertCount(2, $posts);
        $this->assertEquals('Post 1', $posts[0]['title']['rendered']);
    }

    /** @test */
    public function it_throws_exception_on_failed_get_posts()
    {
        Http::fake([
            'example.com/wp-json/wp/v2/posts*' => Http::response('Unauthorized', 401),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get posts');

        $this->service->getPosts($this->site);
    }

    /** @test */
    public function it_creates_post_via_mcp_when_available()
    {
        $this->site->update([
            'capabilities' => [
                ['name' => 'create_post', 'description' => 'Create a post'],
            ],
        ]);

        Http::fake([
            'example.com/wp-json/mcp/*' => Http::response([
                'jsonrpc' => '2.0',
                'result' => ['id' => 123],
            ], 200),
        ]);

        $result = $this->service->createPost($this->site, [
            'title' => 'New Post',
            'content' => 'Content',
        ]);

        $this->assertEquals(123, $result['id']);
    }

    /** @test */
    public function it_creates_post_via_rest_api_when_mcp_unavailable()
    {
        $this->site->update(['mcp_enabled' => false]);

        Http::fake([
            'example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 456,
                'title' => ['rendered' => 'New Post'],
                'status' => 'draft',
            ], 201),
        ]);

        $result = $this->service->createPost($this->site, [
            'title' => 'New Post',
            'content' => 'Content',
        ]);

        $this->assertEquals(456, $result['id']);
    }

    /** @test */
    public function it_throws_exception_on_failed_create_post()
    {
        $this->site->update(['mcp_enabled' => false]);

        Http::fake([
            'example.com/wp-json/wp/v2/posts' => Http::response('Bad request', 400),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to create post');

        $this->service->createPost($this->site, ['title' => 'Test']);
    }

    /** @test */
    public function it_updates_post()
    {
        Http::fake([
            'example.com/wp-json/wp/v2/posts/*' => Http::response([
                'id' => 123,
                'title' => ['rendered' => 'Updated Post'],
                'status' => 'publish',
            ], 200),
        ]);

        $result = $this->service->updatePost($this->site, 123, [
            'title' => 'Updated Post',
            'status' => 'publish',
        ]);

        $this->assertEquals(123, $result['id']);
        $this->assertEquals('Updated Post', $result['title']['rendered']);
    }

    /** @test */
    public function it_throws_exception_on_failed_update_post()
    {
        Http::fake([
            'example.com/wp-json/wp/v2/posts/*' => Http::response('Not found', 404),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to update post');

        $this->service->updatePost($this->site, 999, ['title' => 'Test']);
    }

    /** @test */
    public function it_syncs_posts_to_database()
    {
        Http::fake([
            'example.com/wp-json/wp/v2/posts*' => Http::response([
                [
                    'id' => 1,
                    'title' => ['rendered' => 'Post 1'],
                    'slug' => 'post-1',
                    'status' => 'publish',
                    'type' => 'post',
                    'excerpt' => ['rendered' => 'Excerpt 1'],
                    'content' => ['rendered' => '<p>Content 1</p>'],
                    'categories' => [1, 2],
                    'tags' => [3],
                    'link' => 'https://example.com/post-1',
                    'date' => '2024-01-01T10:00:00',
                    'modified' => '2024-01-02T11:00:00',
                ],
            ], 200),
        ]);

        $count = $this->service->syncPosts($this->site);

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('wordpress_posts', [
            'wordpress_site_id' => $this->site->id,
            'wp_post_id' => 1,
            'title' => 'Post 1',
            'slug' => 'post-1',
            'status' => 'publish',
        ]);
    }

    /** @test */
    public function it_gets_categories()
    {
        Http::fake([
            'example.com/wp-json/wp/v2/categories*' => Http::response([
                ['id' => 1, 'name' => 'Category 1'],
                ['id' => 2, 'name' => 'Category 2'],
            ], 200),
        ]);

        $categories = $this->service->getCategories($this->site);

        $this->assertCount(2, $categories);
        $this->assertEquals('Category 1', $categories[0]['name']);
    }

    /** @test */
    public function it_throws_exception_on_failed_get_categories()
    {
        Http::fake([
            'example.com/wp-json/wp/v2/categories*' => Http::response('Error', 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get categories');

        $this->service->getCategories($this->site);
    }

    /** @test */
    public function it_gets_tags()
    {
        Http::fake([
            'example.com/wp-json/wp/v2/tags*' => Http::response([
                ['id' => 1, 'name' => 'Tag 1'],
                ['id' => 2, 'name' => 'Tag 2'],
            ], 200),
        ]);

        $tags = $this->service->getTags($this->site);

        $this->assertCount(2, $tags);
        $this->assertEquals('Tag 1', $tags[0]['name']);
    }

    /** @test */
    public function it_throws_exception_on_failed_get_tags()
    {
        Http::fake([
            'example.com/wp-json/wp/v2/tags*' => Http::response('Error', 500),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get tags');

        $this->service->getTags($this->site);
    }

    /** @test */
    public function it_sends_authorization_header()
    {
        Http::fake([
            'example.com/wp-json/*' => Http::response(['data' => 'test'], 200),
        ]);

        $this->service->testConnection($this->site);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Basic dGVzdDp0ZXN0');
        });
    }

    /** @test */
    public function it_persists_rest_url_after_a_successful_connection()
    {
        $this->site->update(['rest_url' => null]);

        Http::fake([
            'example.com/wp-json*' => Http::response([
                'namespaces' => ['wp/v2'],
            ], 200),
        ]);

        $this->assertTrue($this->service->testConnection($this->site));

        $this->site->refresh();
        $this->assertSame('https://example.com/wp-json', $this->site->rest_url);
        $this->assertNotNull($this->site->last_connected_at);
    }

    /** @test */
    public function it_handshakes_wordpress_rest_auth_without_creating_a_post()
    {
        $this->site->update(['rest_url' => null]);

        Http::fake([
            'example.com/wp-json/wp/v2/users/me' => Http::response([
                'id' => 7,
                'name' => 'justin',
                'slug' => 'justin',
            ], 200),
            'example.com/wp-json*' => Http::response([
                'namespaces' => ['wp/v2'],
            ], 200),
        ]);

        $result = $this->service->handshake($this->site);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['dry_run']);
        $this->assertFalse($result['published']);
        $this->assertSame('https://example.com/wp-json', $result['rest_url']);
        $this->assertSame('justin', $result['authenticated_user']);

        Http::assertNotSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/wp-json/wp/v2/posts');
        });
    }

    /** @test */
    public function it_uploads_media_as_a_raw_file_body_not_json()
    {
        $path = tempnam(sys_get_temp_dir(), 'wpmedia');
        file_put_contents($path, 'fake-png-bytes');

        Http::fake([
            'example.com/wp-json/wp/v2/media*' => Http::response([
                'id' => 99,
                'source_url' => 'https://example.com/img.png',
            ], 201),
        ]);

        $media = $this->service->uploadMedia($this->site, $path, 'hero.png', 'Hero alt');

        $this->assertSame(99, $media['id']);

        Http::assertSent(function ($request) {
            $contentType = $request->header('Content-Type')[0] ?? '';

            return str_contains($request->url(), '/wp-json/wp/v2/media')
                && $request->method() === 'POST'
                && ! str_contains($contentType, 'application/json');
        });

        unlink($path);
    }

    /** @test */
    public function it_strips_quotes_and_control_characters_from_content_disposition_filename()
    {
        $path = tempnam(sys_get_temp_dir(), 'wpmedia');
        file_put_contents($path, 'fake-png-bytes');

        Http::fake([
            'example.com/wp-json/wp/v2/media*' => Http::response([
                'id' => 99,
                'source_url' => 'https://example.com/img.png',
            ], 201),
        ]);

        $this->service->uploadMedia($this->site, $path, "hero\".png\"\r\nInjected: x");

        Http::assertSent(function ($request) {
            $disposition = $request->header('Content-Disposition')[0] ?? '';

            return str_contains($request->url(), '/wp-json/wp/v2/media')
                && $request->method() === 'POST'
                && str_starts_with($disposition, 'attachment; filename="')
                && str_ends_with($disposition, '"')
                && ! str_contains($disposition, "\r")
                && ! str_contains($disposition, "\n")
                && substr_count($disposition, '"') === 2
                && $disposition === 'attachment; filename="hero.pngInjected: x"';
        });

        unlink($path);
    }
}
