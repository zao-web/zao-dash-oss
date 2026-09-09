<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Groq inference service (OpenAI-compatible API).
 *
 * Provides access to Llama 3.3 70B at 275 tokens/sec via Groq's
 * hardware-accelerated API. Used as a critic in the multi-model consortium.
 */
class GroqService
{
    protected string $apiKey;

    protected string $baseUrl = 'https://api.groq.com/openai/v1';

    protected string $defaultModel = 'llama-3.3-70b-versatile';

    public function __construct()
    {
        $this->apiKey = config('services.groq.api_key', '');
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
            ->timeout(60)
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => 8192,
            ]);

        if (! $response->successful()) {
            throw new \Exception("Groq API error ({$model}): ".$response->body());
        }

        $result = $response->json();
        $content = $result['choices'][0]['message']['content'] ?? '';

        Log::debug('GroqService: response received', [
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
