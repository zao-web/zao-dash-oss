<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenRouter inference service (OpenAI-compatible API).
 *
 * Provides access to Gemma 4 26B and other models via OpenRouter's
 * unified endpoint. Used as a drafter in the multi-model consortium.
 */
class OpenRouterService
{
    protected string $apiKey;

    protected string $baseUrl = 'https://openrouter.ai/api/v1';

    protected string $defaultModel = 'google/gemma-4-26b-a4b-it';

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key', '');
    }

    /**
     * Send a message and get a response (OpenAI-compatible format).
     */
    public function message(
        string $prompt,
        ?string $systemPrompt = null,
        array $context = [],
        ?string $model = null
    ): array {
        $model = $model ?? $this->defaultModel;

        $messages = [];

        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        foreach ($context as $msg) {
            $messages[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'],
            ];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $response = Http::withToken($this->apiKey)
            ->withHeaders([
                'HTTP-Referer' => config('app.url'),
                'X-Title' => config('app.name'),
            ])
            ->timeout(90)
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => $model,
                'messages' => $messages,
            ]);

        if (! $response->successful()) {
            throw new \Exception("OpenRouter API error ({$model}): ".$response->body());
        }

        $result = $response->json();
        $content = $result['choices'][0]['message']['content'] ?? '';

        Log::debug('OpenRouterService: response received', [
            'model' => $model,
            'usage' => $result['usage'] ?? [],
        ]);

        return [
            'content' => $content,
            'model' => $result['model'] ?? $model,
            'usage' => $result['usage'] ?? [],
        ];
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }
}
