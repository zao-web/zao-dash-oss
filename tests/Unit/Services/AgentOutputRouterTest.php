<?php

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Notification;
use App\Models\SlackWorkspace;
use App\Models\WordPressSite;
use App\Services\AgentOutputRouter;
use App\Services\Slack\SlackService;
use App\Services\WordPress\WordPressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AgentOutputRouterTest extends TestCase
{
    use RefreshDatabase;

    protected AgentOutputRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = new AgentOutputRouter;
    }

    protected function tearDown(): void
    {
        // Clean up email drafts
        $draftPath = storage_path('app/email-drafts');
        if (File::isDirectory($draftPath)) {
            File::deleteDirectory($draftPath);
        }

        parent::tearDown();
    }

    /** @test */
    public function it_routes_content_agent_to_wordpress()
    {
        $agent = Agent::factory()->create(['slug' => 'landing-page-generator']);
        $site = WordPressSite::factory()->create(['is_active' => true]);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => "Test Landing Page\n\nThis is the content."],
        ]);

        $wpService = $this->createMock(WordPressService::class);
        $wpService->expects($this->once())
            ->method('createPost')
            ->willReturn(['id' => 123, 'status' => 'draft']);

        $this->app->instance(WordPressService::class, $wpService);

        $results = $this->router->route($run);

        $this->assertArrayHasKey('wordpress_draft', $results);
        $this->assertEquals('success', $results['wordpress_draft']['status']);
    }

    /** @test */
    public function it_skips_wordpress_routing_when_no_active_site()
    {
        $agent = Agent::factory()->create(['slug' => 'case-study-writer']);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'Test content'],
        ]);

        $results = $this->router->route($run);

        $this->assertArrayHasKey('wordpress_draft', $results);
        $this->assertEquals('skipped', $results['wordpress_draft']['status']);
        $this->assertStringContainsString('No active WordPress', $results['wordpress_draft']['reason']);
    }

    /** @test */
    public function it_routes_outreach_agent_to_email_draft()
    {
        $agent = Agent::factory()->create(['slug' => 'lead-nurture']);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => "Subject: Follow Up\n\nHello, this is a follow up email."],
            'config' => ['context' => ['client_email' => 'client@example.com']],
        ]);

        $results = $this->router->route($run);

        $this->assertArrayHasKey('email_draft', $results);
        $this->assertEquals('success', $results['email_draft']['status']);

        // Verify draft file was created
        $draftPath = $results['email_draft']['draft_path'];
        $this->assertFileExists($draftPath);

        $draft = json_decode(File::get($draftPath), true);
        $this->assertEquals('Follow Up', $draft['subject']);
        $this->assertEquals('client@example.com', $draft['to']);
    }

    /** @test */
    public function it_routes_monitoring_agent_to_slack()
    {
        $agent = Agent::factory()->create(['slug' => 'client-health-monitor']);
        $workspace = SlackWorkspace::factory()->create(['is_active' => true]);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'Client health alert: Project behind schedule'],
        ]);

        $slackService = $this->createMock(SlackService::class);
        $slackService->expects($this->once())
            ->method('postMessage')
            ->willReturn(['ts' => '1234567890.123456']);

        $this->app->instance(SlackService::class, $slackService);

        $results = $this->router->route($run);

        $this->assertArrayHasKey('slack_alert', $results);
        $this->assertEquals('success', $results['slack_alert']['status']);
    }

    /** @test */
    public function it_skips_slack_routing_when_no_workspace()
    {
        $agent = Agent::factory()->create(['slug' => 'communication-agent']);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'Test message'],
        ]);

        $results = $this->router->route($run);

        $this->assertArrayHasKey('slack_notify', $results);
        $this->assertEquals('skipped', $results['slack_notify']['status']);
    }

    /** @test */
    public function it_creates_notification_for_monitoring_agents()
    {
        $agent = Agent::factory()->create(['slug' => 'client-health-monitor']);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'Alert message'],
        ]);

        $results = $this->router->route($run);

        $this->assertArrayHasKey('notification', $results);
        $this->assertEquals('success', $results['notification']['status']);

        $this->assertDatabaseHas('notifications', [
            'type' => 'agent_output',
        ]);
    }

    /** @test */
    public function it_handles_multiple_destinations()
    {
        $agent = Agent::factory()->create(['slug' => 'lead-nurture']);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'Test output'],
        ]);

        $results = $this->router->route($run);

        // lead-nurture should route to both email_draft and slack_notify
        $this->assertArrayHasKey('email_draft', $results);
        $this->assertArrayHasKey('slack_notify', $results);
    }

    /** @test */
    public function it_returns_empty_for_unknown_agent()
    {
        $agent = Agent::factory()->create(['slug' => 'unknown-agent']);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'Test'],
        ]);

        $results = $this->router->route($run);

        $this->assertEmpty($results);
    }

    /** @test */
    public function it_stores_routing_results_in_run_metadata()
    {
        $agent = Agent::factory()->create(['slug' => 'dev-agent']);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'Test'],
            'metadata' => [],
        ]);

        $this->router->route($run);

        $run->refresh();
        $this->assertArrayHasKey('routing_results', $run->metadata);
    }

    /** @test */
    public function it_extracts_title_from_content()
    {
        $agent = Agent::factory()->create(['slug' => 'case-study-writer']);
        $site = WordPressSite::factory()->create(['is_active' => true]);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => "# Case Study Title\n\nContent here..."],
        ]);

        $wpService = $this->createMock(WordPressService::class);
        $wpService->expects($this->once())
            ->method('createPost')
            ->with(
                $this->anything(),
                $this->callback(function ($data) {
                    return $data['title'] === 'Case Study Title';
                })
            )
            ->willReturn(['id' => 123]);

        $this->app->instance(WordPressService::class, $wpService);

        $this->router->route($run);
    }

    /** @test */
    public function it_uses_fallback_title_when_no_title_found()
    {
        $agent = Agent::factory()->create([
            'slug' => 'landing-page-generator',
            'name' => 'Landing Page Generator',
        ]);
        $site = WordPressSite::factory()->create(['is_active' => true]);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'No title here. Just content.'],
        ]);

        $wpService = $this->createMock(WordPressService::class);
        $wpService->expects($this->once())
            ->method('createPost')
            ->with(
                $this->anything(),
                $this->callback(function ($data) {
                    return str_contains($data['title'], 'Landing Page Generator');
                })
            )
            ->willReturn(['id' => 123]);

        $this->app->instance(WordPressService::class, $wpService);

        $this->router->route($run);
    }

    /** @test */
    public function it_parses_email_subject_from_output()
    {
        $agent = Agent::factory()->create(['slug' => 'upsell-proposal']);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => "Subject: Exciting Proposal\n\nDear client, here's our proposal..."],
        ]);

        $results = $this->router->route($run);

        $draftPath = $results['email_draft']['draft_path'];
        $draft = json_decode(File::get($draftPath), true);

        $this->assertEquals('Exciting Proposal', $draft['subject']);
        $this->assertStringNotContainsString('Subject:', $draft['body']);
    }

    /** @test */
    public function it_formats_slack_message_with_emoji()
    {
        $agent = Agent::factory()->create([
            'slug' => 'communication-agent',
            'name' => 'Communication Agent',
        ]);
        $workspace = SlackWorkspace::factory()->create(['is_active' => true]);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'Test notification'],
        ]);

        $slackService = $this->createMock(SlackService::class);
        $slackService->expects($this->once())
            ->method('postMessage')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($message) {
                    return str_contains($message['text'], ':robot_face:');
                })
            )
            ->willReturn(['ts' => '123']);

        $this->app->instance(SlackService::class, $slackService);

        $this->router->route($run);
    }

    /** @test */
    public function it_truncates_long_output_for_slack()
    {
        $agent = Agent::factory()->create(['slug' => 'dev-agent']);
        $workspace = SlackWorkspace::factory()->create(['is_active' => true]);

        $longOutput = str_repeat('This is a very long message. ', 50);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => $longOutput],
        ]);

        $slackService = $this->createMock(SlackService::class);
        $slackService->expects($this->once())
            ->method('postMessage')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($message) {
                    $text = $message['attachments'][0]['text'];

                    return strlen($text) <= 503; // 500 + '...'
                })
            )
            ->willReturn(['ts' => '123']);

        $this->app->instance(SlackService::class, $slackService);

        $this->router->route($run);
    }

    /** @test */
    public function it_handles_routing_errors_gracefully()
    {
        $agent = Agent::factory()->create(['slug' => 'landing-page-generator']);
        $site = WordPressSite::factory()->create(['is_active' => true]);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'Test content'],
        ]);

        $wpService = $this->createMock(WordPressService::class);
        $wpService->expects($this->once())
            ->method('createPost')
            ->willThrowException(new \Exception('API error'));

        $this->app->instance(WordPressService::class, $wpService);

        $results = $this->router->route($run);

        $this->assertEquals('error', $results['wordpress_draft']['status']);
        $this->assertStringContainsString('API error', $results['wordpress_draft']['message']);
    }

    /** @test */
    public function it_skips_routing_when_output_is_empty()
    {
        $agent = Agent::factory()->create(['slug' => 'case-study-writer']);
        $site = WordPressSite::factory()->create(['is_active' => true]);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => ''],
        ]);

        $results = $this->router->route($run);

        $this->assertEquals('skipped', $results['wordpress_draft']['status']);
        $this->assertStringContainsString('No output', $results['wordpress_draft']['reason']);
    }

    /** @test */
    public function it_can_register_custom_routing_rules()
    {
        $this->router->registerRule('custom-agent', ['wordpress_draft', 'slack_notify']);

        $rules = $this->router->getRulesFor('custom-agent');

        $this->assertEquals(['wordpress_draft', 'slack_notify'], $rules);
    }

    /** @test */
    public function it_gets_empty_rules_for_unknown_agent()
    {
        $rules = $this->router->getRulesFor('nonexistent-agent');

        $this->assertEmpty($rules);
    }

    /** @test */
    public function it_includes_agent_metadata_in_wordpress_post()
    {
        $agent = Agent::factory()->create(['slug' => 'landing-page-generator']);
        $site = WordPressSite::factory()->create(['is_active' => true]);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'Test content'],
        ]);

        $wpService = $this->createMock(WordPressService::class);
        $wpService->expects($this->once())
            ->method('createPost')
            ->with(
                $this->anything(),
                $this->callback(function ($data) use ($run, $agent) {
                    return $data['meta']['agent_run_id'] === $run->id
                        && $data['meta']['agent_slug'] === $agent->slug;
                })
            )
            ->willReturn(['id' => 123]);

        $this->app->instance(WordPressService::class, $wpService);

        $this->router->route($run);
    }

    /** @test */
    public function it_creates_notification_with_action_url()
    {
        $agent = Agent::factory()->create([
            'slug' => 'client-health-monitor',
            'name' => 'Health Monitor',
        ]);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'Test alert'],
        ]);

        $this->router->route($run);

        $notification = Notification::latest()->first();

        $this->assertNotNull($notification);
        $this->assertStringContainsString('/agents/', $notification->action_url);
        $this->assertEquals('View Results', $notification->action_label);
    }

    /** @test */
    public function it_uses_context_for_email_recipient()
    {
        $agent = Agent::factory()->create(['slug' => 'lead-nurture']);

        $run = AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'output' => ['response' => 'Email body'],
            'config' => [
                'context' => [
                    'client_email' => 'test@example.com',
                    'focus' => 'Custom Subject',
                ],
            ],
        ]);

        $results = $this->router->route($run);

        $draftPath = $results['email_draft']['draft_path'];
        $draft = json_decode(File::get($draftPath), true);

        $this->assertEquals('test@example.com', $draft['to']);
        $this->assertStringContainsString('Custom Subject', $draft['subject']);
    }
}
