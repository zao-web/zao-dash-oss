<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

/**
 * Google Gemini API service for multi-model consortium.
 */
class GeminiService
{
    protected string $apiKey;

    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    protected string $defaultModel = 'gemini-1.5-pro';

    public function __construct()
    {
        $this->apiKey = config('services.google.gemini_api_key', env('GEMINI_API_KEY', ''));
    }

    /**
     * Send a message and get a response.
     */
    public function message(
        string $prompt,
        ?string $systemPrompt = null,
        array $context = [],
        ?string $model = null
    ): array {
        $model = $model ?? $this->defaultModel;

        $contents = [];

        // Add context messages
        foreach ($context as $msg) {
            $role = ($msg['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $msg['content']]],
            ];
        }

        // Add current prompt
        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $prompt]],
        ];

        $payload = ['contents' => $contents];

        if ($systemPrompt) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemPrompt]],
            ];
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->timeout(60)->post(
            "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}",
            $payload
        );

        if (! $response->successful()) {
            throw new \Exception('Gemini API error: '.$response->body());
        }

        $result = $response->json();
        $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return [
            'content' => $text,
            'model' => $model,
            'usage' => $result['usageMetadata'] ?? [],
        ];
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }
}
