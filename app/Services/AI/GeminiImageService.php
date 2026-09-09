<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Google Gemini API service for image generation.
 *
 * Supports multiple models:
 * - gemini-3.1-flash-image: Default multimodal image generation
 * - gemini-2.5-flash-image: Multimodal with image output
 * - gemini-3-pro-image-preview: Advanced image generation
 *
 * The legacy Imagen 4 endpoints (imagen-4.0-generate-001 and variants) were
 * discontinued by Google on 2026-08-17 and return 404. The former 'imagen-4'
 * alias is retained for backward compatibility but now routes to
 * gemini-3.1-flash-image via the generateContent endpoint.
 *
 * @see https://ai.google.dev/gemini-api/docs/image-generation
 */
class GeminiImageService
{
    protected string $apiKey;

    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    /**
     * Available models for image generation.
     *
     * @var array<string, array{model: string, endpoint: string, type: string}>
     */
    protected array $models = [
        'gemini-3.1-flash' => [
            'model' => 'gemini-3.1-flash-image',
            'endpoint' => 'generateContent',
            'type' => 'gemini',
        ],
        'imagen-4' => [
            'model' => 'gemini-3.1-flash-image',
            'endpoint' => 'generateContent',
            'type' => 'gemini',
        ],
        'gemini-flash' => [
            'model' => 'gemini-2.5-flash-image',
            'endpoint' => 'generateContent',
            'type' => 'gemini',
        ],
        'gemini-pro' => [
            'model' => 'gemini-3-pro-image-preview',
            'endpoint' => 'generateContent',
            'type' => 'gemini',
        ],
    ];

    protected string $defaultModel = 'gemini-3.1-flash';

    public function __construct()
    {
        $this->apiKey = config('services.google.gemini_api_key', '');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Generate an image from a text prompt.
     *
     * @param  string  $prompt  Image description
     * @param  string  $model  Model alias: 'gemini-3.1-flash', 'gemini-flash', or 'gemini-pro'
     * @param  array  $options  Additional options (aspectRatio, etc.)
     * @return array{images: array<array{base64: string, mimeType: string}>, model: string}
     *
     * @throws \Exception
     */
    public function generate(
        string $prompt,
        string $model = 'gemini-3.1-flash',
        array $options = []
    ): array {
        if (! $this->isConfigured()) {
            throw new \Exception('Gemini API key is not configured');
        }

        $modelConfig = $this->models[$model] ?? $this->models[$this->defaultModel];

        return $this->generateWithGemini($prompt, $modelConfig, $options);
    }

    /**
     * Generate image using Gemini model (generateContent endpoint).
     */
    protected function generateWithGemini(string $prompt, array $modelConfig, array $options): array
    {
        $aspectRatio = $options['aspectRatio'] ?? '1:1';

        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
                'imageConfig' => [
                    'aspectRatio' => $aspectRatio,
                ],
            ],
        ];

        if (isset($options['imageSize'])) {
            $payload['generationConfig']['imageConfig']['imageSize'] = $options['imageSize'];
        }

        $response = Http::withHeaders([
            'x-goog-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(120)->post(
            "{$this->baseUrl}/models/{$modelConfig['model']}:{$modelConfig['endpoint']}",
            $payload
        );

        if (! $response->successful()) {
            Log::error('Gemini Image API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception("Gemini Image API error: {$response->status()} - {$response->body()}");
        }

        $result = $response->json();
        $images = [];
        $textResponse = '';

        foreach ($result['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (isset($part['inlineData'])) {
                    $images[] = [
                        'base64' => $part['inlineData']['data'],
                        'mimeType' => $part['inlineData']['mimeType'] ?? 'image/png',
                    ];
                }
                if (isset($part['text'])) {
                    $textResponse .= $part['text'];
                }
            }
        }

        return [
            'images' => $images,
            'text' => $textResponse,
            'model' => $modelConfig['model'],
        ];
    }

    /**
     * Generate and save image to storage.
     *
     * @param  string  $prompt  Image description
     * @param  string  $path  Storage path (without extension)
     * @param  string  $disk  Storage disk
     * @param  string  $model  Model alias
     * @param  array  $options  Generation options
     * @return array{path: string, url: string|null, model: string}
     */
    public function generateAndSave(
        string $prompt,
        string $path,
        string $disk = 'public',
        string $model = 'gemini-3.1-flash',
        array $options = []
    ): array {
        $result = $this->generate($prompt, $model, $options);

        if (empty($result['images'])) {
            throw new \Exception('No images generated');
        }

        $image = $result['images'][0];
        $extension = match ($image['mimeType']) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        $fullPath = "{$path}.{$extension}";
        $imageData = base64_decode($image['base64']);

        Storage::disk($disk)->put($fullPath, $imageData);

        return [
            'path' => $fullPath,
            'url' => Storage::disk($disk)->url($fullPath),
            'model' => $result['model'],
        ];
    }

    /**
     * Get available model aliases.
     *
     * @return array<string>
     */
    public function availableModels(): array
    {
        return array_keys($this->models);
    }

    /**
     * Generate an image with reference images for style guidance.
     *
     * Uses Gemini models to generate images that match the style of provided references.
     *
     * @param  string  $prompt  Image description
     * @param  array<array{base64: string, mimeType: string}>  $referenceImages  Reference images for style
     * @param  array  $options  Additional options (aspectRatio, etc.)
     * @return array{images: array<array{base64: string, mimeType: string}>, model: string, text?: string}
     *
     * @throws \Exception
     */
    public function generateWithReferences(
        string $prompt,
        array $referenceImages,
        array $options = []
    ): array {
        if (! $this->isConfigured()) {
            throw new \Exception('Gemini API key is not configured');
        }

        // Gemini Pro is best for reference-based generation
        $model = $options['model'] ?? 'gemini-pro';
        $modelConfig = $this->models[$model] ?? $this->models['gemini-pro'];
        $aspectRatio = $options['aspectRatio'] ?? '16:9';

        // Build multi-modal prompt with reference images
        $parts = [];

        // Add reference images first with context
        if (! empty($referenceImages)) {
            $parts[] = ['text' => 'Use the following reference images to guide the style, colors, and visual approach:'];

            foreach ($referenceImages as $refImage) {
                $parts[] = [
                    'inlineData' => [
                        'mimeType' => $refImage['mimeType'],
                        'data' => $refImage['base64'],
                    ],
                ];
            }

            $parts[] = ['text' => 'Now, create a new image that follows the style of the references above:'];
        }

        // Add the main prompt
        $parts[] = ['text' => $prompt];

        $payload = [
            'contents' => [
                [
                    'parts' => $parts,
                ],
            ],
            'generationConfig' => [
                'responseModalities' => ['TEXT', 'IMAGE'],
                'imageConfig' => [
                    'aspectRatio' => $aspectRatio,
                ],
            ],
        ];

        if (isset($options['imageSize'])) {
            $payload['generationConfig']['imageConfig']['imageSize'] = $options['imageSize'];
        }

        $response = Http::withHeaders([
            'x-goog-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->timeout(180)->post(
            "{$this->baseUrl}/models/{$modelConfig['model']}:{$modelConfig['endpoint']}",
            $payload
        );

        if (! $response->successful()) {
            Log::error('Gemini Reference Image API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \Exception("Gemini Reference Image API error: {$response->status()} - {$response->body()}");
        }

        $result = $response->json();
        $images = [];
        $textResponse = '';

        foreach ($result['candidates'] ?? [] as $candidate) {
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (isset($part['inlineData'])) {
                    $images[] = [
                        'base64' => $part['inlineData']['data'],
                        'mimeType' => $part['inlineData']['mimeType'] ?? 'image/png',
                    ];
                }
                if (isset($part['text'])) {
                    $textResponse .= $part['text'];
                }
            }
        }

        return [
            'images' => $images,
            'text' => $textResponse,
            'model' => $modelConfig['model'],
        ];
    }

    /**
     * Generate with references and save to storage.
     *
     * @param  string  $prompt  Image description
     * @param  array<array{base64: string, mimeType: string}>  $referenceImages  Reference images for style
     * @param  string  $path  Storage path (without extension)
     * @param  string  $disk  Storage disk
     * @param  array  $options  Generation options
     * @return array{path: string, url: string|null, model: string}
     */
    public function generateWithReferencesAndSave(
        string $prompt,
        array $referenceImages,
        string $path,
        string $disk = 'public',
        array $options = []
    ): array {
        $result = $this->generateWithReferences($prompt, $referenceImages, $options);

        if (empty($result['images'])) {
            throw new \Exception('No images generated with references');
        }

        $image = $result['images'][0];
        $extension = match ($image['mimeType']) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => 'png',
        };

        $fullPath = "{$path}.{$extension}";
        $imageData = base64_decode($image['base64']);

        Storage::disk($disk)->put($fullPath, $imageData);

        return [
            'path' => $fullPath,
            'url' => Storage::disk($disk)->url($fullPath),
            'model' => $result['model'],
        ];
    }
}
