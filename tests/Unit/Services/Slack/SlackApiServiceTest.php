<?php

namespace Tests\Unit\Services\Slack;

use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SlackApiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected SlackApiService $service;

    protected SlackWorkspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SlackApiService;
        $this->workspace = SlackWorkspace::factory()->create([
            'access_token' => 'test-token',
        ]);
    }

    /** @test */
    public function it_lists_channels()
    {
        Http::fake([
            'slack.com/api/conversations.list*' => Http::response([
                'ok' => true,
                'channels' => [
                    [
                        'id' => 'C1234',
                        'name' => 'general',
                        'is_private' => false,
                        'is_shared' => false,
                    ],
                    [
                        'id' => 'C5678',
                        'name' => 'random',
                        'is_private' => false,
                        'is_shared' => false,
                    ],
                ],
            ], 200),
        ]);

        $channels = $this->service->listChannels($this->workspace);

        $this->assertCount(2, $channels);
        $this->assertEquals('general', $channels[0]['name']);
        $this->assertEquals('random', $channels[1]['name']);
    }

    /** @test */
    public function it_handles_pagination_when_listing_channels()
    {
        Http::fake([
            'slack.com/api/conversations.list*cursor=*' => Http::response([
                'ok' => true,
                'channels' => [
                    ['id' => 'C5678', 'name' => 'random', 'is_private' => false],
                ],
                'response_metadata' => ['next_cursor' => null],
            ], 200),
            'slack.com/api/conversations.list*' => Http::response([
                'ok' => true,
                'channels' => [
                    ['id' => 'C1234', 'name' => 'general', 'is_private' => false],
                ],
                'response_metadata' => ['next_cursor' => 'cursor123'],
            ], 200),
        ]);

        $channels = $this->service->listChannels($this->workspace);

        $this->assertCount(2, $channels);
    }

    /** @test */
    public function it_syncs_channels()
    {
        Http::fake([
            'slack.com/api/conversations.list*' => Http::response([
                'ok' => true,
                'channels' => [
                    [
                        'id' => 'C1234',
                        'name' => 'general',
                        'is_private' => false,
                        'is_shared' => false,
                    ],
                ],
            ], 200),
        ]);

        $count = $this->service->syncChannels($this->workspace);

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('slack_channels', [
            'workspace_id' => $this->workspace->id,
            'channel_id' => 'C1234',
            'channel_name' => 'general',
        ]);
    }

    /** @test */
    public function it_gets_channel_history()
    {
        $channel = SlackChannel::factory()->create([
            'workspace_id' => $this->workspace->id,
            'channel_id' => 'C1234',
        ]);

        Http::fake([
            'slack.com/api/conversations.history*' => Http::response([
                'ok' => true,
                'messages' => [
                    ['ts' => '1234.5678', 'text' => 'Hello world'],
                ],
            ], 200),
        ]);

        $result = $this->service->getChannelHistory($this->workspace, $channel);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['messages']);
        $this->assertEquals('Hello world', $result['messages'][0]['text']);
    }

    /** @test */
    public function it_throws_exception_on_failed_history_fetch()
    {
        $channel = SlackChannel::factory()->create([
            'workspace_id' => $this->workspace->id,
            'channel_id' => 'C1234',
        ]);

        Http::fake([
            'slack.com/api/conversations.history*' => Http::response([
                'ok' => false,
                'error' => 'channel_not_found',
            ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to get channel history');

        $this->service->getChannelHistory($this->workspace, $channel);
    }

    /** @test */
    public function it_gets_thread_replies()
    {
        $channel = SlackChannel::factory()->create([
            'workspace_id' => $this->workspace->id,
            'channel_id' => 'C1234',
        ]);

        Http::fake([
            'slack.com/api/conversations.replies*' => Http::response([
                'ok' => true,
                'messages' => [
                    ['ts' => '1234.5678', 'text' => 'Thread message'],
                    ['ts' => '1234.5679', 'text' => 'Reply'],
                ],
            ], 200),
        ]);

        $replies = $this->service->getThreadReplies($this->workspace, $channel, '1234.5678');

        $this->assertCount(2, $replies);
        $this->assertEquals('Thread message', $replies[0]['text']);
    }

    /** @test */
    public function it_gets_user_info()
    {
        Http::fake([
            'slack.com/api/users.info*' => Http::response([
                'ok' => true,
                'user' => [
                    'id' => 'U1234',
                    'name' => 'john',
                    'real_name' => 'John Doe',
                    'profile' => ['email' => 'john@example.com'],
                    'is_restricted' => false,
                ],
            ], 200),
        ]);

        $userInfo = $this->service->getUserInfo($this->workspace, 'U1234');

        $this->assertEquals('John Doe', $userInfo['name']);
        $this->assertEquals('john@example.com', $userInfo['email']);
        $this->assertFalse($userInfo['is_external']);
    }

    /** @test */
    public function it_handles_failed_user_info()
    {
        Http::fake([
            'slack.com/api/users.info*' => Http::response([
                'ok' => false,
            ], 200),
        ]);

        $userInfo = $this->service->getUserInfo($this->workspace, 'U1234');

        $this->assertEquals('Unknown', $userInfo['name']);
        $this->assertFalse($userInfo['is_external']);
    }

    /** @test */
    public function it_stores_message()
    {
        $channel = SlackChannel::factory()->create([
            'workspace_id' => $this->workspace->id,
            'channel_id' => 'C1234',
        ]);

        Http::fake([
            'slack.com/api/users.info*' => Http::response([
                'ok' => true,
                'user' => [
                    'name' => 'john',
                    'real_name' => 'John Doe',
                    'profile' => ['email' => 'john@example.com'],
                    'is_restricted' => false,
                ],
            ], 200),
        ]);

        $message = $this->service->storeMessage($this->workspace, $channel, [
            'ts' => '1234.5678',
            'user' => 'U1234',
            'text' => 'Test message',
        ]);

        $this->assertDatabaseHas('slack_messages', [
            'channel_id' => $channel->id,
            'message_ts' => '1234.5678',
            'user_name' => 'John Doe',
            'content' => 'Test message',
        ]);
    }

    /** @test */
    public function it_syncs_thread()
    {
        $channel = SlackChannel::factory()->create([
            'workspace_id' => $this->workspace->id,
            'channel_id' => 'C1234',
        ]);

        Http::fake([
            'slack.com/api/conversations.replies*' => Http::response([
                'ok' => true,
                'messages' => [
                    ['ts' => '1234.5678', 'user' => 'U1234', 'text' => 'Message 1'],
                    ['ts' => '1234.5679', 'user' => 'U5678', 'text' => 'Message 2'],
                ],
            ], 200),
            'slack.com/api/users.info*' => Http::response([
                'ok' => true,
                'user' => [
                    'name' => 'user',
                    'real_name' => 'User',
                    'is_restricted' => false,
                ],
            ], 200),
        ]);

        $thread = $this->service->syncThread($this->workspace, $channel, '1234.5678');

        $this->assertDatabaseHas('slack_threads', [
            'channel_id' => $channel->id,
            'thread_ts' => '1234.5678',
            'message_count' => 2,
        ]);
    }

    /** @test */
    public function it_posts_message()
    {
        Http::fake([
            'slack.com/api/chat.postMessage*' => Http::response([
                'ok' => true,
                'ts' => '1234.5678',
            ], 200),
        ]);

        $result = $this->service->postMessage($this->workspace, 'C1234', 'Hello!');

        $this->assertTrue($result['ok']);
        $this->assertEquals('1234.5678', $result['ts']);

        Http::assertSent(function ($request) {
            return $request['channel'] === 'C1234' &&
                   $request['text'] === 'Hello!';
        });
    }

    /** @test */
    public function it_throws_exception_on_failed_post()
    {
        Http::fake([
            'slack.com/api/chat.postMessage*' => Http::response([
                'ok' => false,
                'error' => 'channel_not_found',
            ], 200),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Failed to post message');

        $this->service->postMessage($this->workspace, 'C1234', 'Hello!');
    }

    /** @test */
    public function it_gets_permalink()
    {
        Http::fake([
            'slack.com/api/chat.getPermalink*' => Http::response([
                'ok' => true,
                'permalink' => 'https://slack.com/archives/C1234/p12345678',
            ], 200),
        ]);

        $permalink = $this->service->getPermalink($this->workspace, 'C1234', '1234.5678');

        $this->assertEquals('https://slack.com/archives/C1234/p12345678', $permalink);
    }

    /** @test */
    public function it_returns_null_on_failed_permalink()
    {
        Http::fake([
            'slack.com/api/chat.getPermalink*' => Http::response([
                'ok' => false,
            ], 200),
        ]);

        $permalink = $this->service->getPermalink($this->workspace, 'C1234', '1234.5678');

        $this->assertNull($permalink);
    }

    /** @test */
    public function it_sends_authorization_token()
    {
        Http::fake([
            'slack.com/api/conversations.list*' => Http::response([
                'ok' => true,
                'channels' => [],
            ], 200),
        ]);

        $this->service->listChannels($this->workspace);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }
}
