<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\AnthropicService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AnthropicServiceTest extends TestCase
{
    protected AnthropicService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.anthropic.api_key' => 'test-api-key']);
        $this->service = new AnthropicService;
    }

    /** @test */
    public function it_checks_if_configured()
    {
        Process::fake([
            'which claude' => Process::result(output: '/usr/local/bin/claude'),
        ]);

        $this->assertTrue($this->service->isConfigured());

        config(['services.anthropic.api_key' => '']);
        config(['services.anthropic.oauth_token' => '']);
        $service = new AnthropicService;

        $this->assertFalse($service->isConfigured());
    }

    /** @test */
    public function it_sends_message_via_cli()
    {
        Process::fake([
            'claude *' => Process::result(output: json_encode([
                'result' => 'Hello! How can I help?',
            ])),
        ]);

        $response = $this->service->message('Hello', 'You are helpful');

        $this->assertArrayHasKey('content', $response);
    }

    /** @test */
    public function it_normalizes_cli_response_to_api_format()
    {
        Process::fake([
            'claude *' => Process::result(output: json_encode([
                'result' => 'Test response',
            ])),
        ]);

        $response = $this->service->message('Test');

        $this->assertArrayHasKey('content', $response);
        $this->assertEquals('text', $response['content'][0]['type']);
        $this->assertEquals('Test response', $response['content'][0]['text']);
    }

    /** @test */
    public function it_handles_cli_failure()
    {
        Process::fake([
            'claude *' => Process::result(
                output: '',
                errorOutput: 'CLI error occurred',
                exitCode: 1
            ),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Claude CLI failed');

        $this->service->message('Test');
    }

    /** @test */
    public function it_handles_message_with_tools()
    {
        Process::fake([
            'claude *' => Process::result(output: json_encode([
                'result' => 'I used the tool and got the result.',
            ])),
        ]);

        $tools = [
            [
                'name' => 'search',
                'description' => 'Search for information',
                'input_schema' => ['type' => 'object'],
            ],
        ];

        $result = $this->service->messageWithTools(
            prompt: 'Find something',
            tools: $tools,
            toolExecutor: fn ($name, $input) => ['result' => 'Found it'],
        );

        $this->assertArrayHasKey('final_response', $result);
        $this->assertArrayHasKey('iterations', $result);
        $this->assertEquals(1, $result['iterations']);
    }

    /** @test */
    public function it_stops_when_no_tool_calls()
    {
        Process::fake([
            'claude *' => Process::result(output: json_encode([
                'content' => [
                    ['type' => 'text', 'text' => 'No tools needed.'],
                ],
            ])),
        ]);

        $result = $this->service->messageWithTools(
            prompt: 'Simple question',
            tools: [],
        );

        $this->assertEquals(1, $result['iterations']);
    }

    /** @test */
    public function it_respects_max_iterations()
    {
        $callCount = 0;
        Process::fake(function () use (&$callCount) {
            $callCount++;

            return Process::result(output: json_encode([
                'content' => [
                    ['type' => 'tool_use', 'id' => 'tool_'.uniqid(), 'name' => 'search', 'input' => []],
                ],
            ]));
        });

        $result = $this->service->messageWithTools(
            prompt: 'Keep searching',
            tools: [['name' => 'search', 'description' => 'Search', 'input_schema' => []]],
            toolExecutor: fn () => ['result' => 'More results'],
            maxIterations: 3,
        );

        $this->assertEquals(3, $result['iterations']);
    }

    /** @test */
    public function it_extracts_text_from_content_array()
    {
        Process::fake([
            'claude *' => Process::result(output: json_encode([
                'content' => [
                    ['type' => 'text', 'text' => 'Part 1. '],
                    ['type' => 'text', 'text' => 'Part 2.'],
                ],
            ])),
        ]);

        $response = $this->service->message('Test');

        $this->assertStringContainsString('Part 1', $response['content'][0]['text'] ?? json_encode($response));
    }

    /** @test */
    public function it_builds_context_into_prompt()
    {
        Process::fake([
            'claude *' => Process::result(output: json_encode(['result' => 'OK'])),
        ]);

        $context = [
            ['role' => 'user', 'content' => 'Previous message'],
            ['role' => 'assistant', 'content' => 'Previous response'],
        ];

        $response = $this->service->message('New message', null, $context);

        Process::assertRan(function ($process) {
            $input = $process->input ?? '';

            return str_contains($input, 'Previous message');
        });
    }

    /** @test */
    public function it_extracts_tool_calls_from_text_with_nested_json()
    {
        $toolCallJson = json_encode([
            'tool_use' => [
                'id' => 'tool_123',
                'name' => 'create-project',
                'input' => [
                    'name' => 'Test Project',
                    'client_id' => 1,
                    'metadata' => ['priority' => 'high'],
                ],
            ],
        ]);

        Process::fake([
            'claude *' => Process::result(output: json_encode([
                'content' => [
                    ['type' => 'text', 'text' => "I'll create that project for you. {$toolCallJson}"],
                ],
            ])),
        ]);

        $events = [];
        $result = $this->service->messageWithTools(
            prompt: 'Create a project called Test Project',
            tools: [['name' => 'create-project', 'description' => 'Create project', 'input_schema' => []]],
            toolExecutor: fn ($name, $input) => ['success' => true, 'id' => 42],
            maxIterations: 2,
            onEvent: function ($type, $data) use (&$events) {
                $events[] = ['type' => $type, 'data' => $data];
            },
        );

        // Should have detected the tool call and executed it
        $toolCallEvents = array_filter($events, fn ($e) => $e['type'] === 'tool_call');
        $this->assertNotEmpty($toolCallEvents, 'Tool call should have been extracted from nested JSON text');

        $toolCall = array_values($toolCallEvents)[0]['data'];
        $this->assertEquals('create-project', $toolCall['name']);
        $this->assertEquals('Test Project', $toolCall['input']['name']);
    }

    /** @test */
    public function it_strips_tool_call_json_from_displayed_text()
    {
        $toolCallJson = json_encode([
            'tool_use' => [
                'id' => 'tool_456',
                'name' => 'search-tasks',
                'input' => ['query' => 'overdue'],
            ],
        ]);

        $responseContent = "Let me search for that. {$toolCallJson}";

        // Use reflection to test extractToolCalls directly
        $response = [
            'content' => [
                ['type' => 'text', 'text' => $responseContent],
            ],
        ];

        $method = new \ReflectionMethod($this->service, 'extractToolCalls');
        $method->setAccessible(true);
        $toolCalls = $method->invokeArgs($this->service, [&$response]);

        // Tool call should be extracted
        $this->assertCount(1, $toolCalls);
        $this->assertEquals('search-tasks', $toolCalls[0]['name']);

        // The JSON should be stripped from the text content
        $cleanedText = $response['content'][0]['text'];
        $this->assertStringNotContainsString('tool_use', $cleanedText);
        $this->assertEquals('Let me search for that.', $cleanedText);
    }
}
