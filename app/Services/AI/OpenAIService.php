<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;

/**
 * OpenAI GPT API service for multi-model consortium.
 */
class OpenAIService
{
    protected ?string $apiKey = null;

    protected string $baseUrl = 'https://api.openai.com/v1';

    protected string $defaultModel = 'gpt-4o';

    public function __construct()
    {
        $apiKey = config('services.openai.api_key') ?? config('services.openai.key') ?? '';
        $this->apiKey = ! empty($apiKey) ? $apiKey : null;
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
        $this->ensureConfigured();

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

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(60)->post("{$this->baseUrl}/chat/completions", [
            'model' => $model ?? $this->defaultModel,
            'messages' => $messages,
            'max_tokens' => 2048,
        ]);

        if (! $response->successful()) {
            throw new \Exception('OpenAI API error: '.$response->body());
        }

        $result = $response->json();

        return [
            'content' => $result['choices'][0]['message']['content'] ?? '',
            'model' => $result['model'] ?? $model,
            'usage' => $result['usage'] ?? [],
        ];
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * Ensure service is configured before making API calls.
     *
     * @throws \RuntimeException If API key is not configured
     */
    protected function ensureConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException(
                'OpenAI API key is not configured. Set OPENAI_API_KEY in your environment or services.openai.api_key in config.'
            );
        }
    }

    /**
     * Generate an image using DALL-E 3.
     *
     * @param  string  $prompt  The image description
     * @param  string  $size  Image size: '1024x1024', '1792x1024', '1024x1792'
     * @param  string  $quality  'standard' or 'hd'
     * @param  string  $style  'vivid' or 'natural'
     * @return array{url: string, revised_prompt: string}
     */
    public function generateImage(
        string $prompt,
        string $size = '1792x1024',
        string $quality = 'standard',
        string $style = 'natural'
    ): array {
        $this->ensureConfigured();

        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post("{$this->baseUrl}/images/generations", [
            'model' => 'dall-e-3',
            'prompt' => $prompt,
            'n' => 1,
            'size' => $size,
            'quality' => $quality,
            'style' => $style,
        ]);

        if (! $response->successful()) {
            throw new \Exception('DALL-E API error: '.$response->body());
        }

        $result = $response->json();
        $imageData = $result['data'][0] ?? [];

        return [
            'url' => $imageData['url'] ?? '',
            'revised_prompt' => $imageData['revised_prompt'] ?? $prompt,
        ];
    }
}
