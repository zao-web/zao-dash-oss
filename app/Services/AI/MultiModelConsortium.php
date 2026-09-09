<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * Multi-model consortium for high-stakes outputs.
 *
 * Queries multiple LLMs in parallel and uses a reasoning agent to consolidate
 * their outputs through:
 * - Conflict resolution
 * - Logical consistency checking
 * - Factual alignment
 * - Hallucination reduction via agreement
 */
class MultiModelConsortium
{
    protected array $providers = [];

    protected array $enabledProviders = [];

    public function __construct(
        protected AnthropicService $anthropic,
        protected OpenAIService $openai,
        protected GeminiService $gemini,
        protected OpenRouterService $openRouter,
        protected GroqService $groq,
    ) {
        $this->providers = [
            'claude' => $anthropic,
            'gpt' => $openai,
            'gemini' => $gemini,
            'gemma4' => $openRouter,
            'llama' => $groq,
        ];

        // Only use configured providers
        $this->enabledProviders = array_filter(
            $this->providers,
            fn ($provider) => $provider->isConfigured()
        );
    }

    /**
     * Generate content using multiple models and consolidate.
     */
    public function generate(
        string $prompt,
        ?string $systemPrompt = null,
        array $context = [],
        array $options = []
    ): ConsortiumResult {
        $minAgreement = $options['min_agreement'] ?? 2;
        $useReasoning = $options['use_reasoning'] ?? true;

        // Collect responses from all enabled providers
        $responses = $this->queryAllProviders($prompt, $systemPrompt, $context);

        if (count($responses) < $minAgreement) {
            Log::warning('Consortium: Not enough provider responses', [
                'required' => $minAgreement,
                'received' => count($responses),
            ]);
        }

        // If only one provider available, return directly
        if (count($responses) === 1) {
            $provider = array_key_first($responses);

            return new ConsortiumResult(
                content: $responses[$provider]['content'],
                consolidated: false,
                providers: [$provider],
                individualResponses: $responses,
                confidence: 0.5, // Low confidence with single provider
                reasoning: 'Single provider response - no consolidation performed'
            );
        }

        // Consolidate using reasoning agent
        if ($useReasoning) {
            return $this->consolidateWithReasoning($responses, $prompt);
        }

        // Fallback: return Claude's response with metadata
        return new ConsortiumResult(
            content: $responses['claude']['content'] ?? array_values($responses)[0]['content'],
            consolidated: false,
            providers: array_keys($responses),
            individualResponses: $responses,
            confidence: 0.7,
            reasoning: 'Multiple responses collected but reasoning disabled'
        );
    }

    /**
     * Query all enabled providers in parallel.
     */
    protected function queryAllProviders(
        string $prompt,
        ?string $systemPrompt,
        array $context
    ): array {
        $responses = [];

        // Use parallel execution where supported
        foreach ($this->enabledProviders as $name => $provider) {
            try {
                $startTime = microtime(true);

                if ($name === 'claude') {
                    $result = $provider->message($prompt, $systemPrompt, $context);
                    $content = $this->extractClaudeContent($result);
                } else {
                    $result = $provider->message($prompt, $systemPrompt, $context);
                    $content = $result['content'] ?? '';
                }

                $responses[$name] = [
                    'content' => $content,
                    'model' => $result['model'] ?? $name,
                    'latency_ms' => (microtime(true) - $startTime) * 1000,
                ];

                Log::debug("Consortium: {$name} responded", [
                    'latency_ms' => $responses[$name]['latency_ms'],
                ]);

            } catch (\Throwable $e) {
                Log::warning("Consortium: {$name} failed", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $responses;
    }

    /**
     * Extract text content from Claude API response.
     */
    protected function extractClaudeContent(array $result): string
    {
        $content = $result['content'] ?? [];

        if (is_string($content)) {
            return $content;
        }

        $text = '';
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        return $text;
    }

    /**
     * Consolidate multiple responses using Claude as reasoning agent.
     */
    protected function consolidateWithReasoning(array $responses, string $originalPrompt): ConsortiumResult
    {
        $consolidationPrompt = $this->buildConsolidationPrompt($responses, $originalPrompt);

        try {
            $result = $this->anthropic->message(
                prompt: $consolidationPrompt,
                systemPrompt: $this->getReasoningSystemPrompt(),
            );

            $consolidated = $this->extractClaudeContent($result);
            $parsed = $this->parseConsolidatedResponse($consolidated);

            return new ConsortiumResult(
                content: $parsed['content'],
                consolidated: true,
                providers: array_keys($responses),
                individualResponses: $responses,
                confidence: $parsed['confidence'],
                reasoning: $parsed['reasoning'],
                conflicts: $parsed['conflicts'] ?? []
            );

        } catch (\Throwable $e) {
            Log::error('Consortium reasoning failed', ['error' => $e->getMessage()]);

            // Fallback to Claude's original response
            return new ConsortiumResult(
                content: $responses['claude']['content'] ?? array_values($responses)[0]['content'],
                consolidated: false,
                providers: array_keys($responses),
                individualResponses: $responses,
                confidence: 0.6,
                reasoning: 'Reasoning consolidation failed: '.$e->getMessage()
            );
        }
    }

    /**
     * Build prompt for the reasoning consolidation agent.
     */
    protected function buildConsolidationPrompt(array $responses, string $originalPrompt): string
    {
        $responsesText = '';
        foreach ($responses as $provider => $response) {
            $responsesText .= "### {$provider} Response:\n{$response['content']}\n\n";
        }

        return <<<PROMPT
You are tasked with consolidating multiple AI model responses into a single, high-quality output.

## Original Prompt
{$originalPrompt}

## Model Responses
{$responsesText}

## Your Task
Analyze these responses and produce a consolidated output that:
1. Identifies areas of agreement (factual alignment)
2. Resolves any conflicts between responses
3. Removes hallucinations (claims only one model makes without support)
4. Combines the best elements from each response

Respond in this exact format:

CONFIDENCE: [0.0-1.0 based on agreement level]

REASONING:
[Brief explanation of your consolidation decisions]

CONFLICTS:
[List any significant disagreements found, or "None"]

CONSOLIDATED OUTPUT:
[Your final consolidated response to the original prompt]
PROMPT;
    }

    /**
     * System prompt for the reasoning agent.
     */
    protected function getReasoningSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a reasoning agent that consolidates outputs from multiple AI models.

Your role is NOT to create new content, but to synthesize and validate existing responses through:
- Conflict resolution: When models disagree, favor claims with broader support
- Logical consistency: Ensure the final output is internally consistent
- Factual alignment: Prioritize facts that multiple models agree on
- Deduplication: Remove redundant information
- Relevance filtering: Keep only what addresses the original prompt

Be objective and analytical. Trust consensus over single-model claims.
PROMPT;
    }

    /**
     * Parse the structured consolidation response.
     */
    protected function parseConsolidatedResponse(string $response): array
    {
        $confidence = 0.7;
        $reasoning = '';
        $conflicts = [];
        $content = $response;

        // Extract CONFIDENCE
        if (preg_match('/CONFIDENCE:\s*([0-9.]+)/i', $response, $matches)) {
            $confidence = min(1.0, max(0.0, (float) $matches[1]));
        }

        // Extract REASONING
        if (preg_match('/REASONING:\s*\n(.*?)(?=\n(?:CONFLICTS|CONSOLIDATED OUTPUT):)/is', $response, $matches)) {
            $reasoning = trim($matches[1]);
        }

        // Extract CONFLICTS
        if (preg_match('/CONFLICTS:\s*\n(.*?)(?=\nCONSOLIDATED OUTPUT:)/is', $response, $matches)) {
            $conflictsText = trim($matches[1]);
            if (strtolower($conflictsText) !== 'none') {
                $conflicts = array_filter(array_map('trim', explode("\n", $conflictsText)));
            }
        }

        // Extract CONSOLIDATED OUTPUT
        if (preg_match('/CONSOLIDATED OUTPUT:\s*\n(.+)$/is', $response, $matches)) {
            $content = trim($matches[1]);
        }

        return [
            'confidence' => $confidence,
            'reasoning' => $reasoning,
            'conflicts' => $conflicts,
            'content' => $content,
        ];
    }

    /**
     * Generate a high-quality output via a 4-step debate loop:
     *
     *  Round 1 — Gemma 4 drafts the initial response
     *  Round 2 — Llama 3.3 critiques the draft (gaps, errors, weak sections)
     *  Round 3 — Gemma 4 revises based on the critique
     *  Round 4 — Claude Sonnet judges and produces the final polished output
     *
     * Falls back to the standard generate() if Gemma 4 or Groq aren't configured.
     */
    public function generateWithDebate(
        string $prompt,
        ?string $systemPrompt = null,
        array $context = [],
    ): ConsortiumResult {
        if (! isset($this->enabledProviders['gemma4']) || ! isset($this->enabledProviders['llama'])) {
            Log::info('Consortium debate: gemma4 or llama not configured, falling back to standard generate');

            return $this->generate($prompt, $systemPrompt, $context);
        }

        try {
            // Round 1: Gemma 4 drafts
            Log::debug('Consortium debate: round 1 — Gemma 4 drafting');
            $draft = $this->enabledProviders['gemma4']->message($prompt, $systemPrompt, $context);
            $draftContent = $draft['content'];

            // Round 2: Llama critiques the draft
            Log::debug('Consortium debate: round 2 — Llama 3.3 critiquing');
            $critiquePrompt = <<<PROMPT
You are a critical reviewer. The following is a draft response to a task. Identify specific weaknesses:
- Missing or incomplete sections
- Factual errors or unsupported claims
- Weak arguments or vague language
- Anything that would reduce the output's quality or persuasiveness

## Original Task
{$prompt}

## Draft Response
{$draftContent}

List your critique as numbered points. Be specific and actionable. Do NOT rewrite the draft — just critique it.
PROMPT;

            $critique = $this->enabledProviders['llama']->message($critiquePrompt);
            $critiqueContent = $critique['content'];

            // Round 3: Gemma 4 revises based on critique
            Log::debug('Consortium debate: round 3 — Gemma 4 revising');
            $revisionPrompt = <<<PROMPT
You wrote a draft response to a task. A critic has reviewed it and identified weaknesses. Revise your draft to address every critique point.

## Original Task
{$prompt}

## Your Draft
{$draftContent}

## Critic's Notes
{$critiqueContent}

Write the complete revised response, incorporating all feedback. Do not reference the critique or the revision process in your output.
PROMPT;

            $revised = $this->enabledProviders['gemma4']->message($revisionPrompt, $systemPrompt);
            $revisedContent = $revised['content'];

            // Round 4: Claude judges and polishes
            Log::debug('Consortium debate: round 4 — Claude judging');
            $judgePrompt = <<<PROMPT
You are a final judge reviewing two versions of a response to a task. Polish the revised version for quality, correctness, and persuasiveness. Fix any remaining issues.

## Original Task
{$prompt}

## Initial Draft
{$draftContent}

## Revised Draft (after critique)
{$revisedContent}

## Critic's Notes
{$critiqueContent}

Output ONLY the final polished response. Do not include commentary, section headers about "final response", or meta-text.
PROMPT;

            $final = $this->anthropic->message($judgePrompt, $this->getReasoningSystemPrompt());
            $finalContent = $this->extractClaudeContent($final);

            return new ConsortiumResult(
                content: $finalContent,
                consolidated: true,
                providers: ['gemma4', 'llama', 'claude'],
                individualResponses: [
                    'gemma4_draft' => ['content' => $draftContent, 'model' => $draft['model']],
                    'llama_critique' => ['content' => $critiqueContent, 'model' => $critique['model']],
                    'gemma4_revised' => ['content' => $revisedContent, 'model' => $revised['model']],
                    'claude_final' => ['content' => $finalContent, 'model' => $final['model'] ?? 'claude'],
                ],
                confidence: 0.92,
                reasoning: 'Four-round debate: Gemma 4 draft → Llama 3.3 critique → Gemma 4 revision → Claude final judge'
            );

        } catch (\Throwable $e) {
            Log::error('Consortium debate failed, falling back to standard generate', ['error' => $e->getMessage()]);

            return $this->generate($prompt, $systemPrompt, $context);
        }
    }

    /**
     * Get list of available providers.
     */
    public function getAvailableProviders(): array
    {
        return array_keys($this->enabledProviders);
    }

    /**
     * Check if consortium is available (at least 2 providers).
     */
    public function isAvailable(): bool
    {
        return count($this->enabledProviders) >= 2;
    }
}
