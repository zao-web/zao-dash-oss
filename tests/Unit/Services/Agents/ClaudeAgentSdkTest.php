<?php

namespace Tests\Unit\Services\Agents;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\ClaudeAgentSdk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaudeAgentSdkTest extends TestCase
{
    use RefreshDatabase;

    protected ClaudeAgentSdk $sdk;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.api_key' => 'test-api-key']);
        $this->sdk = new ClaudeAgentSdk;
    }

    /** @test */
    public function it_executes_simple_agent_request()
    {
        $agent = Agent::factory()->create([
            'model' => 'sonnet',
            'system_prompt' => 'You are a helpful assistant.',
        ]);

        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_123',
                'content' => [
                    ['type' => 'text', 'text' => 'Hello! How can I help you?'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 50,
                    'output_tokens' => 20,
                ],
            ], 200),
        ]);

        $result = $this->sdk->execute($agent, $run, ['prompt' => 'Hello']);

        $this->assertTrue($result->succeeded());
        $this->assertEquals('Hello! How can I help you?', $result->output['response']);
        $this->assertEquals(50, $result->inputTokens);
        $this->assertEquals(20, $result->outputTokens);
        $this->assertGreaterThan(0, $result->costUsd);
    }

    /** @test */
    public function it_handles_tool_use_in_response()
    {
        $agent = Agent::factory()->create([
            'model' => 'sonnet',
            'tools' => ['web_search'],
        ]);

        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [
                        [
                            'type' => 'tool_use',
                            'id' => 'tool_123',
                            'name' => 'web_search',
                            'input' => ['query' => 'test query'],
                        ],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                ], 200)
                ->push([
                    'content' => [
                        ['type' => 'text', 'text' => 'Based on the search results...'],
                    ],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 150, 'output_tokens' => 75],
                ], 200),
        ]);

        $result = $this->sdk->execute($agent, $run, ['prompt' => 'Search for something']);

        $this->assertTrue($result->succeeded());
        $this->assertArrayHasKey('tool_results', $result->output);
        $this->assertNotEmpty($result->output['tool_results']);
        $this->assertEquals(250, $result->inputTokens); // 100 + 150
        $this->assertEquals(125, $result->outputTokens); // 50 + 75
    }

    /** @test */
    public function it_calculates_cost_correctly()
    {
        $agent = Agent::factory()->create(['model' => 'sonnet']);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Test']],
                'stop_reason' => 'end_turn',
                'usage' => [
                    'input_tokens' => 1000,
                    'output_tokens' => 1000,
                ],
            ], 200),
        ]);

        $result = $this->sdk->execute($agent, $run, ['prompt' => 'Test']);

        // Sonnet: $3/M input, $15/M output
        // 1000 input tokens = 0.003, 1000 output tokens = 0.015
        // Total = 0.018
        $this->assertEquals(0.018, $result->costUsd);
    }

    /** @test */
    public function it_handles_api_errors()
    {
        $agent = Agent::factory()->create(['model' => 'sonnet']);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => ['message' => 'Rate limit exceeded'],
            ], 429),
        ]);

        $result = $this->sdk->execute($agent, $run, ['prompt' => 'Test']);

        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString('API error: 429', $result->output['error']);
    }

    /** @test */
    public function it_handles_network_exceptions()
    {
        $agent = Agent::factory()->create(['model' => 'sonnet']);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => function () {
                throw new \Exception('Network timeout');
            },
        ]);

        $result = $this->sdk->execute($agent, $run, ['prompt' => 'Test']);

        $this->assertFalse($result->succeeded());
        $this->assertStringContainsString('Network timeout', $result->output['error']);
    }

    /** @test */
    public function it_limits_turns_to_prevent_infinite_loops()
    {
        $agent = Agent::factory()->create([
            'model' => 'sonnet',
            'tools' => ['web_search'],
        ]);

        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        // Simulate agent always requesting tools
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'tool_123',
                        'name' => 'web_search',
                        'input' => ['query' => 'test'],
                    ],
                ],
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ], 200),
        ]);

        $result = $this->sdk->execute($agent, $run, ['prompt' => 'Test']);

        // Should still succeed but stop at max turns
        $this->assertTrue($result->succeeded());
        $this->assertEquals(25, $result->output['turns']); // Max turns
    }

    /** @test */
    public function it_builds_system_prompt_with_agent_identity()
    {
        $agent = Agent::factory()->create([
            'name' => 'Test Agent',
            'model' => 'sonnet',
            'system_prompt' => 'Custom instructions',
        ]);

        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ], 200),
        ]);

        $this->sdk->execute($agent, $run, ['prompt' => 'Test']);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($body['system'], 'You are Test Agent')
                && str_contains($body['system'], 'Custom instructions');
        });
    }

    /** @test */
    public function it_includes_context_in_conversation()
    {
        $agent = Agent::factory()->create(['model' => 'sonnet']);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ], 200),
        ]);

        $this->sdk->execute($agent, $run, [
            'prompt' => 'Test',
            'context' => ['key' => 'value'],
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            // Should have 3 messages: context user, assistant ack, prompt user
            return count($body['messages']) === 3;
        });
    }

    /** @test */
    public function it_resolves_model_shortcuts()
    {
        $agent = Agent::factory()->create(['model' => 'opus']);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ], 200),
        ]);

        $this->sdk->execute($agent, $run, ['prompt' => 'Test']);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['model'] === 'claude-opus-4-20250514';
        });
    }

    /** @test */
    public function it_sends_correct_api_headers()
    {
        $agent = Agent::factory()->create(['model' => 'sonnet']);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ], 200),
        ]);

        $this->sdk->execute($agent, $run, ['prompt' => 'Test']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'test-api-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request->hasHeader('content-type', 'application/json');
        });
    }

    /** @test */
    public function it_tracks_duration()
    {
        $agent = Agent::factory()->create(['model' => 'sonnet']);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ], 200),
        ]);

        $result = $this->sdk->execute($agent, $run, ['prompt' => 'Test']);

        $this->assertGreaterThan(0, $result->durationSeconds);
    }

    /** @test */
    public function it_executes_create_task_tool()
    {
        $agent = Agent::factory()->create([
            'model' => 'sonnet',
            'tools' => ['create_task'],
        ]);

        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [
                        [
                            'type' => 'tool_use',
                            'id' => 'tool_123',
                            'name' => 'create_task',
                            'input' => [
                                'title' => 'Test Task',
                                'description' => 'Task description',
                                'priority' => 'high',
                            ],
                        ],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                ], 200)
                ->push([
                    'content' => [['type' => 'text', 'text' => 'Task created']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 150, 'output_tokens' => 25],
                ], 200),
        ]);

        $result = $this->sdk->execute($agent, $run, ['prompt' => 'Create a task']);

        $this->assertTrue($result->succeeded());
        $this->assertDatabaseHas('tasks', [
            'title' => 'Test Task',
            'priority' => 'high',
        ]);
    }

    /** @test */
    public function it_executes_send_notification_tool()
    {
        $agent = Agent::factory()->create([
            'model' => 'sonnet',
            'tools' => ['send_notification'],
        ]);

        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [
                        [
                            'type' => 'tool_use',
                            'id' => 'tool_123',
                            'name' => 'send_notification',
                            'input' => [
                                'title' => 'Test Notification',
                                'message' => 'Test message',
                                'severity' => 'info',
                            ],
                        ],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                ], 200)
                ->push([
                    'content' => [['type' => 'text', 'text' => 'Notification sent']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 150, 'output_tokens' => 25],
                ], 200),
        ]);

        $result = $this->sdk->execute($agent, $run, ['prompt' => 'Send notification']);

        $this->assertTrue($result->succeeded());
        $this->assertDatabaseHas('notifications', [
            'title' => 'Test Notification',
            'message' => 'Test message',
        ]);
    }

    /** @test */
    public function it_executes_api_call_tool()
    {
        $agent = Agent::factory()->create([
            'model' => 'sonnet',
            'tools' => ['api_call'],
        ]);

        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        // Mock both Anthropic API and the external API call
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [
                        [
                            'type' => 'tool_use',
                            'id' => 'tool_123',
                            'name' => 'api_call',
                            'input' => [
                                'url' => 'https://api.example.com/data',
                                'method' => 'GET',
                            ],
                        ],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                ], 200)
                ->push([
                    'content' => [['type' => 'text', 'text' => 'API call completed']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 150, 'output_tokens' => 25],
                ], 200),
            'api.example.com/*' => Http::response(['data' => 'test'], 200),
        ]);

        $result = $this->sdk->execute($agent, $run, ['prompt' => 'Call API']);

        $this->assertTrue($result->succeeded());
        $toolResults = $result->output['tool_results'];
        $this->assertNotEmpty($toolResults);
        $this->assertEquals('api_call', $toolResults[0]['tool']);
    }

    /** @test */
    public function it_handles_max_tokens_in_config()
    {
        $agent = Agent::factory()->create(['model' => 'sonnet']);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Response']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20],
            ], 200),
        ]);

        $this->sdk->execute($agent, $run, [
            'prompt' => 'Test',
            'max_tokens' => 4096,
        ]);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['max_tokens'] === 4096;
        });
    }

    /** @test */
    public function it_handles_tool_execution_errors_gracefully()
    {
        $agent = Agent::factory()->create([
            'model' => 'sonnet',
            'tools' => ['unknown_tool'],
        ]);

        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push([
                    'content' => [
                        [
                            'type' => 'tool_use',
                            'id' => 'tool_123',
                            'name' => 'unknown_tool',
                            'input' => [],
                        ],
                    ],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
                ], 200)
                ->push([
                    'content' => [['type' => 'text', 'text' => 'Tool error handled']],
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 150, 'output_tokens' => 25],
                ], 200),
        ]);

        $result = $this->sdk->execute($agent, $run, ['prompt' => 'Test']);

        // Should still complete even with tool error
        $this->assertTrue($result->succeeded());
    }
}
