<?php

namespace Tests\Unit\Services\Grok;

use App\Services\Grok\GrokService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GrokServiceTest extends TestCase
{
    use RefreshDatabase;

    protected GrokService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.grok.api_key' => 'test-api-key']);
        $this->service = new GrokService;
    }

    /** @test */
    public function it_checks_if_configured()
    {
        $this->assertTrue($this->service->isConfigured());

        $service = new GrokService(null);
        config(['services.grok.api_key' => null]);
        $service = new GrokService;

        $this->assertFalse($service->isConfigured());
    }

    /** @test */
    public function it_analyzes_trends()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Here are the top trends for Laravel...',
                        ],
                    ],
                ],
                'usage' => ['total_tokens' => 150],
                'model' => 'grok-beta',
            ], 200),
        ]);

        $result = $this->service->analyzeTrends('Laravel');

        $this->assertEquals('Laravel', $result['topic']);
        $this->assertStringContainsString('trends', $result['analysis']);
        $this->assertEquals('grok-beta', $result['model']);
        $this->assertNotNull($result['analyzed_at']);
    }

    /** @test */
    public function it_analyzes_trends_with_options()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Analysis']]],
                'model' => 'grok-beta',
            ], 200),
        ]);

        $this->service->analyzeTrends('React', [
            'timeframe' => 'this week',
            'focus' => 'developer tools',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($body['messages'][1]['content'], 'this week') &&
                   str_contains($body['messages'][1]['content'], 'developer tools');
        });
    }

    /** @test */
    public function it_gets_trending_hashtags()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '[{"hashtag": "#Laravel", "engagement": "high", "trend_direction": "rising", "context": "Popular"}]',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->getTrendingHashtags('web development');

        $this->assertEquals('web development', $result['industry']);
        $this->assertIsArray($result['hashtags']);
        $this->assertNotEmpty($result['hashtags']);
        $this->assertEquals('#Laravel', $result['hashtags'][0]['hashtag']);
    }

    /** @test */
    public function it_caches_trending_hashtags()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '[{"hashtag": "#Test"}]',
                        ],
                    ],
                ],
            ], 200),
        ]);

        // First call
        $result1 = $this->service->getTrendingHashtags('tech');

        // Second call should use cache
        $result2 = $this->service->getTrendingHashtags('tech');

        // Only one HTTP request should be made
        Http::assertSentCount(1);
    }

    /** @test */
    public function it_analyzes_content_formats()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Threads of 5-7 tweets work best...',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->analyzeContentFormats('SaaS marketing');

        $this->assertEquals('SaaS marketing', $result['niche']);
        $this->assertStringContainsString('Threads', $result['analysis']);
    }

    /** @test */
    public function it_gets_topic_sentiment()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Overall sentiment is 70% positive...',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->getTopicSentiment('AI tools');

        $this->assertEquals('AI tools', $result['topic']);
        $this->assertStringContainsString('positive', $result['sentiment']);
    }

    /** @test */
    public function it_suggests_posts()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => "Post 1: Just shipped...\nPost 2: Here's why...",
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->suggestPosts('Acme Corp', 'SaaS', ['New feature launch']);

        $this->assertEquals('Acme Corp', $result['brand']);
        $this->assertEquals('SaaS', $result['industry']);
        $this->assertNotEmpty($result['suggestions']);
    }

    /** @test */
    public function it_optimizes_posts()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Score: 7/10. Improvements: Add a hook...',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->optimizePost('This is my draft post', 'engagement');

        $this->assertEquals('This is my draft post', $result['original']);
        $this->assertEquals('engagement', $result['goal']);
        $this->assertStringContainsString('Score', $result['optimization']);
    }

    /** @test */
    public function it_performs_chat_completion()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Hello! How can I help?',
                        ],
                    ],
                ],
                'usage' => ['total_tokens' => 20],
                'model' => 'grok-beta',
            ], 200),
        ]);

        $result = $this->service->chat([
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $this->assertEquals('Hello! How can I help?', $result['content']);
        $this->assertArrayHasKey('usage', $result);
        $this->assertEquals('grok-beta', $result['model']);
    }

    /** @test */
    public function it_sends_correct_headers()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'test']]],
            ], 200),
        ]);

        $this->service->chat([
            ['role' => 'user', 'content' => 'test'],
        ]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-api-key') &&
                   $request->hasHeader('Content-Type', 'application/json');
        });
    }

    /** @test */
    public function it_uses_custom_model()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'test']]],
                'model' => 'grok-2',
            ], 200),
        ]);

        $this->service->chat(
            [['role' => 'user', 'content' => 'test']],
            ['model' => 'grok-2']
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['model'] === 'grok-2';
        });
    }

    /** @test */
    public function it_uses_custom_temperature()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'test']]],
            ], 200),
        ]);

        $this->service->chat(
            [['role' => 'user', 'content' => 'test']],
            ['temperature' => 0.9]
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['temperature'] === 0.9;
        });
    }

    /** @test */
    public function it_uses_custom_max_tokens()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'test']]],
            ], 200),
        ]);

        $this->service->chat(
            [['role' => 'user', 'content' => 'test']],
            ['max_tokens' => 1000]
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['max_tokens'] === 1000;
        });
    }

    /** @test */
    public function it_throws_exception_when_not_configured()
    {
        $service = new GrokService(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Grok API key not configured');

        $service->chat([['role' => 'user', 'content' => 'test']]);
    }

    /** @test */
    public function it_throws_exception_on_api_error()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'error' => 'Invalid request',
            ], 400),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Grok API error: 400');

        $this->service->chat([['role' => 'user', 'content' => 'test']]);
    }

    /** @test */
    public function it_handles_json_extraction_from_hashtags_response()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'Here are the hashtags: [{"hashtag": "#test"}] and some extra text',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->getTrendingHashtags('test');

        $this->assertNotEmpty($result['hashtags']);
        $this->assertEquals('#test', $result['hashtags'][0]['hashtag']);
    }

    /** @test */
    public function it_handles_malformed_json_in_hashtags()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'No JSON here, just text',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->getTrendingHashtags('test');

        $this->assertEmpty($result['hashtags']);
    }

    /** @test */
    public function it_builds_trend_analysis_prompt_correctly()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'test']]],
            ], 200),
        ]);

        $this->service->analyzeTrends('Laravel', [
            'timeframe' => 'last week',
            'focus' => 'performance',
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $prompt = $body['messages'][1]['content'];

            return str_contains($prompt, 'Laravel') &&
                   str_contains($prompt, 'last week') &&
                   str_contains($prompt, 'performance');
        });
    }

    /** @test */
    public function it_includes_system_prompts()
    {
        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'test']]],
            ], 200),
        ]);

        $this->service->analyzeTrends('test');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['messages'][0]['role'] === 'system' &&
                   str_contains($body['messages'][0]['content'], 'trend analyst');
        });
    }

    /** @test */
    public function it_accepts_custom_api_key_in_constructor()
    {
        $service = new GrokService('custom-api-key');

        Http::fake([
            'api.x.ai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'test']]],
            ], 200),
        ]);

        $service->chat([['role' => 'user', 'content' => 'test']]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer custom-api-key');
        });
    }
}
