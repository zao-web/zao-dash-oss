<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Replicate API Service for Flux image generation.
 *
 * Uses Flux Pro 1.1 for high-quality image generation.
 *
 * @see https://replicate.com/black-forest-labs/flux-1.1-pro
 */
class ReplicateService
{
    protected string $apiKey;

    protected string $baseUrl = 'https://api.replicate.com/v1';

    // Flux 1.1 Pro - best quality
    protected string $fluxModel = 'black-forest-labs/flux-1.1-pro';

    public function __construct()
    {
        $this->apiKey = config('services.replicate.api_key', '');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Generate an image using Flux Pro.
     *
     * @param  string  $prompt  Image description
     * @param  string  $aspectRatio  Aspect ratio: '1:1', '16:9', '9:16', '4:3', '3:4'
     * @param  bool  $rawMode  Raw mode for more literal prompts
     * @return array{url: string, seed: int}
     */
    public function generateImage(
        string $prompt,
        string $aspectRatio = '16:9',
        bool $rawMode = false
    ): array {
        // Start prediction
        $response = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
            'Prefer' => 'wait', // Wait for completion (up to 60s)
        ])->timeout(120)->post("{$this->baseUrl}/models/{$this->fluxModel}/predictions", [
            'input' => [
                'prompt' => $prompt,
                'aspect_ratio' => $aspectRatio,
                'output_format' => 'png',
                'output_quality' => 90,
                'safety_tolerance' => 2, // Allow most content
                'prompt_upsampling' => ! $rawMode, // Enhance prompts unless raw mode
            ],
        ]);

        if (! $response->successful()) {
            Log::error('Replicate API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception("Replicate API error: {$response->status()} - {$response->body()}");
        }

        $prediction = $response->json();

        // If using Prefer: wait, output should be ready
        if ($prediction['status'] === 'succeeded') {
            return [
                'url' => $prediction['output'] ?? '',
                'seed' => $prediction['input']['seed'] ?? 0,
            ];
        }

        // If still processing, poll for result
        if (in_array($prediction['status'], ['starting', 'processing'])) {
            return $this->pollPrediction($prediction['id']);
        }

        throw new \Exception('Prediction failed: '.($prediction['error'] ?? 'Unknown error'));
    }

    /**
     * Poll for prediction completion.
     */
    protected function pollPrediction(string $predictionId, int $maxAttempts = 30): array
    {
        for ($i = 0; $i < $maxAttempts; $i++) {
            sleep(2);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ])->get("{$this->baseUrl}/predictions/{$predictionId}");

            if (! $response->successful()) {
                throw new \Exception("Failed to check prediction status: {$response->body()}");
            }

            $prediction = $response->json();

            if ($prediction['status'] === 'succeeded') {
                return [
                    'url' => $prediction['output'] ?? '',
                    'seed' => $prediction['input']['seed'] ?? 0,
                ];
            }

            if ($prediction['status'] === 'failed') {
                throw new \Exception('Prediction failed: '.($prediction['error'] ?? 'Unknown error'));
            }

            if ($prediction['status'] === 'canceled') {
                throw new \Exception('Prediction was canceled');
            }
        }

        throw new \Exception('Prediction timed out after polling');
    }
}
