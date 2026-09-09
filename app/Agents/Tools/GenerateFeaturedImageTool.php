<?php

namespace App\Agents\Tools;

use App\Models\WordPressSite;
use App\Services\AI\OpenAIService;
use App\Services\AI\ReplicateService;
use Illuminate\Support\Facades\Http;

/**
 * Generate a featured image for blog posts using Flux Pro.
 *
 * Uses Replicate's Flux 1.1 Pro model for high-quality image generation.
 * Falls back to DALL-E 3 if Replicate is not configured.
 *
 * Creates professional, brand-appropriate images and optionally
 * uploads them to WordPress media library.
 */
class GenerateFeaturedImageTool extends BaseTool
{
    public function category(): string
    {
        return 'content';
    }

    public function id(): string
    {
        return 'generate-featured-image';
    }

    public function name(): string
    {
        return 'Generate Featured Image';
    }

    public function description(): string
    {
        return 'Generate a professional featured image for blog posts using DALL-E 3. Creates brand-appropriate visuals that avoid stock photo clichés. Optionally uploads to WordPress media library.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => [
                    'type' => 'string',
                    'description' => 'Blog post title - used to generate relevant imagery',
                ],
                'topic' => [
                    'type' => 'string',
                    'description' => 'Main topic/subject of the post (e.g., "WordPress security", "Laravel APIs")',
                ],
                'style' => [
                    'type' => 'string',
                    'enum' => ['professional', 'technical', 'creative', 'minimalist'],
                    'description' => 'Visual style: professional (clean corporate), technical (code/diagrams), creative (artistic), minimalist (simple/elegant)',
                    'default' => 'professional',
                ],
                'upload_to_wordpress' => [
                    'type' => 'boolean',
                    'description' => 'Upload the generated image to WordPress media library',
                    'default' => false,
                ],
                'custom_prompt' => [
                    'type' => 'string',
                    'description' => 'Optional: override the auto-generated prompt with a custom description',
                ],
            ],
            'required' => ['title', 'topic'],
        ];
    }

    public function requiresApproval(): bool
    {
        return true; // Image generation costs money
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function execute(array $params): array
    {
        $replicate = app(ReplicateService::class);
        $openai = app(OpenAIService::class);

        // Prefer Flux Pro via Replicate, fall back to DALL-E
        $useFlux = $replicate->isConfigured();
        $useDalle = ! $useFlux && $openai->isConfigured();

        if (! $useFlux && ! $useDalle) {
            return [
                'success' => false,
                'error' => 'No image generation service configured. Set REPLICATE_API_TOKEN (preferred) or OPENAI_API_KEY.',
            ];
        }

        $title = $params['title'];
        $topic = $params['topic'];
        $style = $params['style'] ?? 'professional';

        // Build the image prompt
        $prompt = $params['custom_prompt'] ?? $this->buildPrompt($title, $topic, $style);

        try {
            if ($useFlux) {
                $result = $replicate->generateImage(
                    prompt: $prompt,
                    aspectRatio: '16:9',
                    rawMode: false
                );
                $imageUrl = $result['url'];
                $model = 'flux-1.1-pro';
            } else {
                $result = $openai->generateImage(
                    prompt: $prompt,
                    size: '1792x1024',
                    quality: 'standard',
                    style: 'natural'
                );
                $imageUrl = $result['url'];
                $model = 'dall-e-3';
            }

            $response = [
                'success' => true,
                'image_url' => $imageUrl,
                'model' => $model,
                'prompt_used' => $prompt,
                'style' => $style,
            ];

            // Upload to WordPress if requested
            if ($params['upload_to_wordpress'] ?? false) {
                $wpResult = $this->uploadToWordPress($imageUrl, $title);
                $response['wordpress'] = $wpResult;
            }

            return $response;

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function buildPrompt(string $title, string $topic, string $style): string
    {
        $styleGuides = [
            'professional' => 'Clean, modern corporate design with subtle gradients. No people, no stock photo clichés, no handshakes. Abstract geometric shapes, clean lines, professional color palette.',
            'technical' => 'Technical illustration style showing code snippets, architectural diagrams, or abstract representations of software concepts. Dark theme with syntax-highlighted code elements.',
            'creative' => 'Artistic, creative interpretation of the concept. Bold colors, interesting compositions, modern design aesthetic. Abstract and metaphorical.',
            'minimalist' => 'Simple, elegant design with lots of white space. Single focal point, muted colors, sophisticated typography-friendly composition.',
        ];

        $styleGuide = $styleGuides[$style] ?? $styleGuides['professional'];

        return "Create a professional blog featured image for an article titled \"{$title}\" about {$topic}.

Style requirements:
{$styleGuide}

Critical rules:
- NO text, logos, or watermarks in the image
- NO generic stock photo concepts (handshakes, lightbulbs, puzzle pieces)
- NO AI clichés (robot hands, glowing brains, floating holograms)
- Create something that a design-conscious tech company would use
- Image should work at 1792x1024 aspect ratio for web headers
- Colors should complement a professional tech blog design";
    }

    protected function uploadToWordPress(string $imageUrl, string $title): array
    {
        $site = WordPressSite::where('is_primary', true)->first()
            ?? WordPressSite::first();

        if (! $site) {
            return [
                'uploaded' => false,
                'error' => 'No WordPress site configured',
            ];
        }

        try {
            // Download image
            $imageContent = Http::timeout(30)->get($imageUrl)->body();
            $filename = \Str::slug($title).'-'.time().'.png';

            // Upload to WordPress media library
            $response = Http::withHeaders([
                'Authorization' => $site->auth_header,
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Content-Type' => 'image/png',
            ])->withBody($imageContent, 'image/png')
                ->post(rtrim($site->url, '/').'/wp-json/wp/v2/media');

            if (! $response->successful()) {
                return [
                    'uploaded' => false,
                    'error' => 'WordPress upload failed: '.$response->body(),
                ];
            }

            $media = $response->json();

            return [
                'uploaded' => true,
                'media_id' => $media['id'] ?? null,
                'media_url' => $media['source_url'] ?? null,
                'wordpress_site' => $site->name,
            ];

        } catch (\Exception $e) {
            return [
                'uploaded' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
