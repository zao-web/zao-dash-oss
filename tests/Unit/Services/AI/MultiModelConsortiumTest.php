<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\AnthropicService;
use App\Services\AI\ConsortiumResult;
use App\Services\AI\GeminiService;
use App\Services\AI\MultiModelConsortium;
use App\Services\AI\OpenAIService;
use Tests\TestCase;

class MultiModelConsortiumTest extends TestCase
{
    protected MultiModelConsortium $consortium;

    protected AnthropicService $mockAnthropic;

    protected OpenAIService $mockOpenAI;

    protected GeminiService $mockGemini;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockAnthropic = $this->createMock(AnthropicService::class);
        $this->mockOpenAI = $this->createMock(OpenAIService::class);
        $this->mockGemini = $this->createMock(GeminiService::class);

        // Mock all as configured
        $this->mockAnthropic->method('isConfigured')->willReturn(true);
        $this->mockOpenAI->method('isConfigured')->willReturn(true);
        $this->mockGemini->method('isConfigured')->willReturn(true);

        $this->consortium = new MultiModelConsortium(
            anthropic: $this->mockAnthropic,
            openai: $this->mockOpenAI,
            gemini: $this->mockGemini
        );
    }

    /** @test */
    public function it_queries_all_providers()
    {
        $this->mockAnthropic->expects($this->once())
            ->method('message')
            ->willReturn([
                'content' => [['type' => 'text', 'text' => 'Claude response']],
                'model' => 'claude-3',
            ]);

        $this->mockOpenAI->expects($this->once())
            ->method('message')
            ->willReturn([
                'content' => 'GPT response',
                'model' => 'gpt-4',
            ]);

        $this->mockGemini->expects($this->once())
            ->method('message')
            ->willReturn([
                'content' => 'Gemini response',
                'model' => 'gemini-pro',
            ]);

        $result = $this->consortium->generate(
            prompt: 'Test prompt',
            systemPrompt: 'You are helpful',
            options: ['use_reasoning' => false]
        );

        $this->assertInstanceOf(ConsortiumResult::class, $result);
        $this->assertCount(3, $result->providers);
    }

    /** @test */
    public function it_consolidates_with_reasoning_agent()
    {
        $this->mockAnthropic->method('message')
            ->willReturnOnConsecutiveCalls(
                // First call: collect responses
                [
                    'content' => [['type' => 'text', 'text' => 'Claude response']],
                    'model' => 'claude-3',
                ],
                // Second call: reasoning consolidation
                [
                    'content' => [['type' => 'text', 'text' => $this->buildConsolidatedResponse()]],
                ]
            );

        $this->mockOpenAI->method('message')
            ->willReturn(['content' => 'GPT response']);

        $this->mockGemini->method('message')
            ->willReturn(['content' => 'Gemini response']);

        $result = $this->consortium->generate(
            prompt: 'Test prompt',
            systemPrompt: 'You are helpful',
            options: ['use_reasoning' => true]
        );

        $this->assertTrue($result->consolidated);
        $this->assertGreaterThan(0, $result->confidence);
    }

    /** @test */
    public function it_returns_single_provider_response_when_only_one_available()
    {
        $this->mockOpenAI->method('isConfigured')->willReturn(false);
        $this->mockGemini->method('isConfigured')->willReturn(false);

        $consortium = new MultiModelConsortium(
            anthropic: $this->mockAnthropic,
            openai: $this->mockOpenAI,
            gemini: $this->mockGemini
        );

        $this->mockAnthropic->method('message')
            ->willReturn([
                'content' => [['type' => 'text', 'text' => 'Claude only']],
            ]);

        $result = $consortium->generate('Test');

        $this->assertFalse($result->consolidated);
        $this->assertEquals(0.5, $result->confidence); // Low confidence with single provider
        $this->assertCount(1, $result->providers);
    }

    /** @test */
    public function it_handles_provider_failures_gracefully()
    {
        $this->mockAnthropic->method('message')
            ->willReturn([
                'content' => [['type' => 'text', 'text' => 'Claude response']],
            ]);

        $this->mockOpenAI->method('message')
            ->willThrowException(new \Exception('API timeout'));

        $this->mockGemini->method('message')
            ->willReturn(['content' => 'Gemini response']);

        $result = $this->consortium->generate(
            prompt: 'Test',
            options: ['use_reasoning' => false]
        );

        // Should succeed with 2 providers despite one failing
        $this->assertCount(2, $result->providers);
        $this->assertNotContains('gpt', $result->providers);
    }

    /** @test */
    public function it_extracts_claude_content_from_blocks()
    {
        $this->mockAnthropic->method('message')
            ->willReturn([
                'content' => [
                    ['type' => 'text', 'text' => 'Part 1'],
                    ['type' => 'text', 'text' => ' Part 2'],
                ],
            ]);

        $this->mockOpenAI->method('message')
            ->willReturn(['content' => 'GPT response']);

        $this->mockGemini->method('message')
            ->willReturn(['content' => 'Gemini response']);

        $result = $this->consortium->generate(
            prompt: 'Test',
            options: ['use_reasoning' => false]
        );

        // Claude response should be concatenated
        $claudeResponse = $result->individualResponses['claude']['content'];
        $this->assertEquals('Part 1 Part 2', $claudeResponse);
    }

    /** @test */
    public function it_parses_consolidated_response_structure()
    {
        $this->mockAnthropic->method('message')
            ->willReturnOnConsecutiveCalls(
                ['content' => [['type' => 'text', 'text' => 'Response 1']]],
                ['content' => [['type' => 'text', 'text' => $this->buildConsolidatedResponse()]]]
            );

        $this->mockOpenAI->method('message')
            ->willReturn(['content' => 'Response 2']);

        $this->mockGemini->method('message')
            ->willReturn(['content' => 'Response 3']);

        $result = $this->consortium->generate('Test', options: ['use_reasoning' => true]);

        $this->assertTrue($result->consolidated);
        $this->assertEquals(0.85, $result->confidence);
        $this->assertStringContainsString('Models agreed', $result->reasoning);
        $this->assertEmpty($result->conflicts);
    }

    /** @test */
    public function it_detects_conflicts_in_consolidated_response()
    {
        $responseWithConflicts = <<<'RESPONSE'
CONFIDENCE: 0.6

REASONING:
Models disagree on key points

CONFLICTS:
- Claude says X, GPT says Y
- Different dates mentioned

CONSOLIDATED OUTPUT:
Final consolidated response
RESPONSE;

        $this->mockAnthropic->method('message')
            ->willReturnOnConsecutiveCalls(
                ['content' => [['type' => 'text', 'text' => 'Response 1']]],
                ['content' => [['type' => 'text', 'text' => $responseWithConflicts]]]
            );

        $this->mockOpenAI->method('message')
            ->willReturn(['content' => 'Response 2']);

        $this->mockGemini->method('message')
            ->willReturn(['content' => 'Response 3']);

        $result = $this->consortium->generate('Test', options: ['use_reasoning' => true]);

        $this->assertTrue($result->hasConflicts());
        $this->assertCount(2, $result->conflicts);
        $this->assertEquals(0.6, $result->confidence);
    }

    /** @test */
    public function it_falls_back_on_reasoning_failure()
    {
        $this->mockAnthropic->method('message')
            ->willReturnCallback(function ($prompt) {
                // First call: return normal response
                if (! str_contains($prompt, 'consolidating')) {
                    return ['content' => [['type' => 'text', 'text' => 'Claude response']]];
                }
                // Second call (reasoning): throw error
                throw new \Exception('Reasoning failed');
            });

        $this->mockOpenAI->method('message')
            ->willReturn(['content' => 'GPT response']);

        $this->mockGemini->method('message')
            ->willReturn(['content' => 'Gemini response']);

        $result = $this->consortium->generate('Test', options: ['use_reasoning' => true]);

        $this->assertFalse($result->consolidated);
        $this->assertEquals(0.6, $result->confidence); // Fallback confidence
        $this->assertStringContainsString('Reasoning consolidation failed', $result->reasoning);
    }

    /** @test */
    public function it_respects_minimum_agreement_option()
    {
        $this->mockAnthropic->method('message')
            ->willReturn(['content' => [['type' => 'text', 'text' => 'Response']]]);

        $this->mockOpenAI->method('isConfigured')->willReturn(false);
        $this->mockGemini->method('isConfigured')->willReturn(false);

        $consortium = new MultiModelConsortium(
            anthropic: $this->mockAnthropic,
            openai: $this->mockOpenAI,
            gemini: $this->mockGemini
        );

        $result = $consortium->generate(
            prompt: 'Test',
            options: ['min_agreement' => 2, 'use_reasoning' => false]
        );

        // Should succeed but note insufficient providers
        $this->assertInstanceOf(ConsortiumResult::class, $result);
    }

    /** @test */
    public function it_checks_if_consortium_is_available()
    {
        $this->assertTrue($this->consortium->isAvailable());

        // Only one provider configured
        $this->mockOpenAI->method('isConfigured')->willReturn(false);
        $this->mockGemini->method('isConfigured')->willReturn(false);

        $singleProviderConsortium = new MultiModelConsortium(
            anthropic: $this->mockAnthropic,
            openai: $this->mockOpenAI,
            gemini: $this->mockGemini
        );

        $this->assertFalse($singleProviderConsortium->isAvailable());
    }

    /** @test */
    public function it_gets_available_providers()
    {
        $providers = $this->consortium->getAvailableProviders();

        $this->assertCount(3, $providers);
        $this->assertContains('claude', $providers);
        $this->assertContains('gpt', $providers);
        $this->assertContains('gemini', $providers);
    }

    /** @test */
    public function it_filters_unconfigured_providers()
    {
        $this->mockGemini->method('isConfigured')->willReturn(false);

        $consortium = new MultiModelConsortium(
            anthropic: $this->mockAnthropic,
            openai: $this->mockOpenAI,
            gemini: $this->mockGemini
        );

        $providers = $consortium->getAvailableProviders();

        $this->assertCount(2, $providers);
        $this->assertNotContains('gemini', $providers);
    }

    /** @test */
    public function it_includes_latency_in_responses()
    {
        $this->mockAnthropic->method('message')
            ->willReturn(['content' => [['type' => 'text', 'text' => 'Claude']]]);

        $this->mockOpenAI->method('message')
            ->willReturn(['content' => 'GPT']);

        $this->mockGemini->method('message')
            ->willReturn(['content' => 'Gemini']);

        $result = $this->consortium->generate(
            prompt: 'Test',
            options: ['use_reasoning' => false]
        );

        foreach ($result->individualResponses as $response) {
            $this->assertArrayHasKey('latency_ms', $response);
            $this->assertGreaterThanOrEqual(0, $response['latency_ms']);
        }
    }

    /** @test */
    public function it_builds_consolidation_prompt()
    {
        $this->mockAnthropic->method('message')
            ->willReturnCallback(function ($prompt, $systemPrompt) {
                if (str_contains($prompt, 'consolidating')) {
                    // Verify consolidation prompt structure
                    $this->assertStringContainsString('Original Prompt', $prompt);
                    $this->assertStringContainsString('Model Responses', $prompt);
                    $this->assertStringContainsString('CONFIDENCE:', $prompt);
                }

                return ['content' => [['type' => 'text', 'text' => $this->buildConsolidatedResponse()]]];
            });

        $this->mockOpenAI->method('message')
            ->willReturn(['content' => 'GPT']);

        $this->mockGemini->method('message')
            ->willReturn(['content' => 'Gemini']);

        $this->consortium->generate('Test', options: ['use_reasoning' => true]);
    }

    /** @test */
    public function it_identifies_high_confidence_results()
    {
        $highConfidenceResponse = <<<'RESPONSE'
CONFIDENCE: 0.95

REASONING:
All models strongly agree

CONFLICTS:
None

CONSOLIDATED OUTPUT:
Highly confident response
RESPONSE;

        $this->mockAnthropic->method('message')
            ->willReturnOnConsecutiveCalls(
                ['content' => [['type' => 'text', 'text' => 'Response']]],
                ['content' => [['type' => 'text', 'text' => $highConfidenceResponse]]]
            );

        $this->mockOpenAI->method('message')
            ->willReturn(['content' => 'Response']);

        $this->mockGemini->method('message')
            ->willReturn(['content' => 'Response']);

        $result = $this->consortium->generate('Test', options: ['use_reasoning' => true]);

        $this->assertTrue($result->isHighConfidence());
        $this->assertGreaterThanOrEqual(0.8, $result->confidence);
    }

    /** @test */
    public function it_passes_context_to_providers()
    {
        $this->mockAnthropic->expects($this->once())
            ->method('message')
            ->with(
                $this->anything(),
                $this->anything(),
                $this->callback(function ($context) {
                    return ! empty($context);
                })
            )
            ->willReturn(['content' => [['type' => 'text', 'text' => 'Response']]]);

        $this->mockOpenAI->method('message')
            ->willReturn(['content' => 'Response']);

        $this->mockGemini->method('message')
            ->willReturn(['content' => 'Response']);

        $this->consortium->generate(
            prompt: 'Test',
            context: [
                ['role' => 'user', 'content' => 'Previous message'],
            ],
            options: ['use_reasoning' => false]
        );
    }

    /** @test */
    public function it_converts_result_to_array()
    {
        $this->mockAnthropic->method('message')
            ->willReturn(['content' => [['type' => 'text', 'text' => 'Response']]]);

        $this->mockOpenAI->method('message')
            ->willReturn(['content' => 'Response']);

        $this->mockGemini->method('message')
            ->willReturn(['content' => 'Response']);

        $result = $this->consortium->generate(
            prompt: 'Test',
            options: ['use_reasoning' => false]
        );

        $array = $result->toArray();

        $this->assertArrayHasKey('content', $array);
        $this->assertArrayHasKey('consolidated', $array);
        $this->assertArrayHasKey('providers', $array);
        $this->assertArrayHasKey('confidence', $array);
        $this->assertArrayHasKey('is_high_confidence', $array);
        $this->assertArrayHasKey('provider_count', $array);
    }

    /**
     * Helper method to build a valid consolidated response.
     */
    protected function buildConsolidatedResponse(): string
    {
        return <<<'RESPONSE'
CONFIDENCE: 0.85

REASONING:
Models agreed on key points with minor variations

CONFLICTS:
None

CONSOLIDATED OUTPUT:
This is the consolidated response combining all model outputs
RESPONSE;
    }
}
