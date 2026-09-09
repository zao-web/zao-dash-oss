<?php

namespace App\Services\MetaAds;

use App\Models\AdCreative;
use App\Models\BrandGuideline;
use App\Models\Client;
use App\Models\MetaAdAccount;
use App\Services\AI\AnthropicService;
use App\Services\AI\GeminiImageService;
use App\Services\AI\OpenAIService;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AdCreativeGenerationService
{
    public function __construct(
        protected AnthropicService $anthropic,
        protected GeminiImageService $gemini,
        protected OpenAIService $openai
    ) {}

    /**
     * Generate ad copy using Claude
     */
    public function generateAdCopy(
        BrandGuideline $brandGuideline,
        string $objective,
        ?string $existingCopy = null
    ): array {
        $systemPrompt = $this->buildCopySystemPrompt($brandGuideline, $objective);

        $prompt = $existingCopy
            ? "Improve this ad copy while maintaining brand voice:\n\n{$existingCopy}"
            : 'Generate compelling ad copy for this campaign.';

        try {
            $response = $this->anthropic->message(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                model: 'sonnet',
                maxTokens: 1024
            );

            $content = $this->extractTextFromResponse($response);

            return $this->parseAdCopy($content);
        } catch (Exception $e) {
            Log::error('Ad copy generation failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Generate variations of existing copy for A/B testing
     */
    public function generateVariations(
        string $baseHeadline,
        string $basePrimaryText,
        BrandGuideline $brandGuideline,
        int $count = 3
    ): array {
        $systemPrompt = $this->buildCopySystemPrompt($brandGuideline, 'OUTCOME_LEADS');

        $prompt = <<<PROMPT
        Generate {$count} variations of this ad copy for A/B testing.

        Original Headline: {$baseHeadline}
        Original Primary Text: {$basePrimaryText}

        Create variations that:
        1. Test different angles (problem/solution, social proof, urgency, authority)
        2. Maintain brand voice
        3. Stay within Meta's character limits (Headline: 40 chars, Primary Text: 125 chars)

        Format as JSON array:
        [
            {"headline": "...", "primary_text": "...", "angle": "problem/solution"},
            {"headline": "...", "primary_text": "...", "angle": "social proof"},
            ...
        ]
        PROMPT;

        try {
            $response = $this->anthropic->message(
                prompt: $prompt,
                systemPrompt: $systemPrompt,
                model: 'sonnet',
                maxTokens: 2048
            );

            $content = $this->extractTextFromResponse($response);

            // Extract JSON from response
            if (preg_match('/\[.*\]/s', $content, $matches)) {
                $variations = json_decode($matches[0], true);
                if ($variations && is_array($variations)) {
                    return $variations;
                }
            }

            // Fallback: return original with slight modifications
            return [
                [
                    'headline' => $baseHeadline,
                    'primary_text' => $basePrimaryText,
                    'angle' => 'control',
                ],
            ];
        } catch (Exception $e) {
            Log::error('Variation generation failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Generate an ad image using Gemini (Gemini 3.1 Flash Image)
     */
    public function generateAdImage(
        BrandGuideline $brandGuideline,
        string $headline,
        string $primaryText,
        array $specs = ['width' => 1200, 'height' => 628]
    ): string {
        $aspectRatio = $this->calculateAspectRatio($specs['width'], $specs['height']);
        $imagePrompt = $this->buildImagePrompt($brandGuideline, $headline, $primaryText);

        try {
            // Use Gemini with improved prompting
            if ($this->gemini->isConfigured()) {
                Log::info('Generating ad image with Gemini', ['prompt' => $imagePrompt]);
                $result = $this->gemini->generateAndSave(
                    prompt: $imagePrompt,
                    path: 'meta-ads/'.uniqid('ad_'),
                    disk: config('filesystems.default'),
                    model: 'gemini-3.1-flash',
                    options: ['aspectRatio' => $aspectRatio]
                );

                return $result['url'];
            }

            // Fallback to DALL-E if Gemini not configured
            Log::info('Gemini not configured, using DALL-E', ['prompt' => $imagePrompt]);
            $result = $this->openai->generateImage($imagePrompt, $specs);
            $imageUrl = $result['url'];
            $storedPath = $this->downloadAndStore($imageUrl, 'dalle');

            return Storage::url($storedPath);
        } catch (Exception $e) {
            Log::error('Image generation failed', ['error' => $e->getMessage()]);
            throw new Exception("Failed to generate ad image: {$e->getMessage()}");
        }
    }

    /**
     * Generate carousel images
     */
    public function generateCarouselImages(
        BrandGuideline $brandGuideline,
        array $carouselItems,
        int $count = 5
    ): array {
        $images = [];

        foreach (array_slice($carouselItems, 0, $count) as $item) {
            try {
                $imageUrl = $this->generateAdImage(
                    $brandGuideline,
                    $item['headline'] ?? '',
                    $item['description'] ?? '',
                    ['width' => 1080, 'height' => 1080] // Carousel format
                );

                $images[] = $imageUrl;
            } catch (Exception $e) {
                Log::warning('Failed to generate carousel image', ['error' => $e->getMessage()]);

                continue;
            }
        }

        return $images;
    }

    /**
     * Generate a complete ad creative
     */
    public function generateCompleteAd(
        Client $client,
        MetaAdAccount $metaAdAccount,
        string $objective,
        string $creativeType = 'single_image'
    ): AdCreative {
        // Get or create brand guideline
        $brandGuideline = $client->brandGuidelines()->first();
        if (! $brandGuideline) {
            $brandGuideline = $this->createDefaultBrandGuideline($client);
        }

        // Generate copy
        $copy = $this->generateAdCopy($brandGuideline, $objective);

        // Generate creative based on type
        $creative = AdCreative::create([
            'meta_ad_account_id' => $metaAdAccount->id,
            'name' => "Auto-generated: {$copy['headline']}",
            'type' => $creativeType === 'carousel' ? 'carousel' : 'image',
            'headline' => $copy['headline'],
            'primary_text' => $copy['primary_text'],
            'description' => $copy['description'] ?? null,
            'brand_guideline_id' => $brandGuideline->id,
            'generation_prompt' => $this->buildImagePrompt($brandGuideline, $copy['headline'], $copy['primary_text']),
            'generation_metadata' => [
                'model' => 'gemini-3.1-flash-image',
                'provider' => 'google-gemini',
                'model_name' => 'Gemini 3.1 Flash Image',
                'objective' => $objective,
                'generated_at' => now()->toIso8601String(),
            ],
        ]);

        if ($creativeType === 'carousel') {
            $carouselItems = $this->generateCarouselContent($brandGuideline, $objective);
            $images = $this->generateCarouselImages($brandGuideline, $carouselItems);

            $creative->update([
                'carousel_items' => array_map(function ($item, $index) use ($images) {
                    return [
                        'image_url' => $images[$index] ?? null,
                        'headline' => $item['headline'],
                        'description' => $item['description'],
                        'link' => $item['link'] ?? null,
                    ];
                }, $carouselItems, array_keys($carouselItems)),
            ]);
        } else {
            $imageUrl = $this->generateAdImage($brandGuideline, $copy['headline'], $copy['primary_text']);
            $creative->update(['image_url' => $imageUrl]);
        }

        return $creative->fresh();
    }

    /**
     * Generate test variants for A/B testing
     */
    public function generateTestVariants(
        AdCreative $controlCreative,
        string $testType = 'headline',
        int $variantCount = 2
    ): array {
        $brandGuideline = $controlCreative->brandGuideline;
        $variants = [];

        if ($testType === 'headline') {
            $variations = $this->generateVariations(
                $controlCreative->headline,
                $controlCreative->primary_text,
                $brandGuideline,
                $variantCount
            );

            foreach ($variations as $variation) {
                $variant = $controlCreative->replicate();
                $variant->headline = $variation['headline'];
                $variant->name = "Variant: {$variation['headline']}";
                $variant->save();
                $variants[] = $variant;
            }
        } elseif ($testType === 'image') {
            for ($i = 0; $i < $variantCount; $i++) {
                $variant = $controlCreative->replicate();
                $variant->image_url = $this->generateAdImage(
                    $brandGuideline,
                    $controlCreative->headline,
                    $controlCreative->primary_text
                );
                $variant->name = "Variant Image {$i}";
                $variant->save();
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    /**
     * Build system prompt for copy generation
     */
    protected function buildCopySystemPrompt(BrandGuideline $brandGuideline, string $objective): string
    {
        $objectiveMap = [
            'OUTCOME_LEADS' => 'Lead Generation (get people to book consultations, request quotes, sign up)',
            'OUTCOME_AWARENESS' => 'Brand Awareness (educate market, establish authority)',
            'OUTCOME_ENGAGEMENT' => 'Engagement (likes, comments, shares)',
            'OUTCOME_SALES' => 'Direct Sales (purchase products/services)',
            'OUTCOME_TRAFFIC' => 'Website Traffic (drive visitors to landing pages)',
        ];

        $objectiveDesc = $objectiveMap[$objective] ?? 'General Marketing';

        return <<<PROMPT
        You are an expert Meta Ads copywriter specializing in high-converting ad creative.

        Campaign Objective: {$objectiveDesc}

        Brand Guidelines:
        - Brand Voice: {$brandGuideline->brand_voice}
        - Tone: {$brandGuideline->tone}
        - Keywords to Include: {$this->formatArray($brandGuideline->keywords_to_include)}
        - Keywords to Avoid: {$this->formatArray($brandGuideline->keywords_to_avoid)}
        - Imagery Style: {$brandGuideline->imagery_style}

        Ad Copy Best Practices:
        - Headline: 5 words max, outcome-focused, specific numbers
        - Primary Text: 2-3 sentences, problem → solution, social proof
        - Description: Reinforce CTA, add urgency/scarcity
        - Character Limits: Headline (40), Primary Text (125), Description (30)
        - CTAs: Be specific (not "Learn More" → "Book Free Analysis")

        Lead Gen Angles:
        - Problem/Solution: "Cut Admin Time 90% With AI Automation"
        - Social Proof: "20+ Local Businesses Trust Zao"
        - Authority: "20 Years Building Custom Solutions"
        - Risk Reversal: "Save 10+ Hours/Week or Get Paid"

        Generate ad copy that:
        1. Aligns perfectly with brand voice and tone
        2. Uses approved keywords
        3. Avoids forbidden keywords
        4. Stays within character limits
        5. Drives the campaign objective

        Return in this format:
        HEADLINE: [40 chars max]
        PRIMARY_TEXT: [125 chars max]
        DESCRIPTION: [30 chars max]
        PROMPT;
    }

    /**
     * Build image generation prompt
     */
    protected function buildImagePrompt(
        BrandGuideline $brandGuideline,
        string $headline,
        string $primaryText
    ): string {
        $primaryColor = $brandGuideline->primary_colors[0] ?? '#0ea5e9';
        $secondaryColor = $brandGuideline->primary_colors[1] ?? '#f59e0b';

        // Convert hex to color names for better Gemini understanding
        $primary = $this->hexToColorName($primaryColor);
        $secondary = $this->hexToColorName($secondaryColor);

        return "abstract gradient background, {$primary} to {$secondary}, soft smooth waves, minimal geometric shapes, no text, no people, no faces, no words whatsoever, clean professional design, wide banner format";
    }

    protected function hexToColorName(string $hex): string
    {
        return match ($hex) {
            '#0ea5e9' => 'sky blue',
            '#f59e0b' => 'amber orange',
            '#3b82f6' => 'blue',
            '#ef4444' => 'red',
            '#10b981' => 'emerald green',
            '#8b5cf6' => 'purple',
            default => 'blue'
        };
    }

    /**
     * Parse ad copy response from Claude
     */
    protected function parseAdCopy(string $content): array
    {
        $headline = '';
        $primaryText = '';
        $description = '';

        if (preg_match('/HEADLINE:\s*(.+)/i', $content, $matches)) {
            $headline = trim($matches[1]);
        }

        if (preg_match('/PRIMARY_TEXT:\s*(.+)/i', $content, $matches)) {
            $primaryText = trim($matches[1]);
        }

        if (preg_match('/DESCRIPTION:\s*(.+)/i', $content, $matches)) {
            $description = trim($matches[1]);
        }

        return [
            'headline' => substr($headline, 0, 40),
            'primary_text' => substr($primaryText, 0, 125),
            'description' => substr($description, 0, 30),
        ];
    }

    /**
     * Calculate aspect ratio from dimensions
     */
    protected function calculateAspectRatio(int $width, int $height): string
    {
        $ratios = [
            '1:1' => 1.0,
            '16:9' => 1.778,
            '9:16' => 0.5625,
            '4:3' => 1.333,
            '3:4' => 0.75,
        ];

        $targetRatio = $width / $height;
        $closest = '16:9';
        $closestDiff = PHP_FLOAT_MAX;

        foreach ($ratios as $ratio => $value) {
            $diff = abs($value - $targetRatio);
            if ($diff < $closestDiff) {
                $closestDiff = $diff;
                $closest = $ratio;
            }
        }

        return $closest;
    }

    /**
     * Download and store image from URL
     */
    protected function downloadAndStore(string $url, string $source): string
    {
        $contents = file_get_contents($url);
        $filename = 'meta-ads/'.uniqid("{$source}_").'.png';

        Storage::put($filename, $contents);

        return $filename;
    }

    /**
     * Create default brand guideline for client
     */
    protected function createDefaultBrandGuideline(Client $client): BrandGuideline
    {
        return BrandGuideline::create([
            'client_id' => $client->id,
            'name' => 'Default Brand Guidelines',
            'primary_colors' => ['#0ea5e9', '#f59e0b'], // Sky blue + Amber
            'secondary_colors' => ['#64748b', '#f8fafc'],
            'fonts' => ['primary' => 'Inter', 'secondary' => 'Merriweather'],
            'brand_voice' => 'Professional, technical, approachable',
            'tone' => 'Confident but not salesy',
            'keywords_to_include' => ['workflow', 'automation', 'efficiency', 'AI'],
            'keywords_to_avoid' => ['cheap', 'easy', 'simple', 'quick fix'],
            'imagery_style' => 'Clean, modern, minimal, professional',
        ]);
    }

    /**
     * Generate carousel content structure
     */
    protected function generateCarouselContent(BrandGuideline $brandGuideline, string $objective): array
    {
        // Simplified: return static structure for now
        // TODO: Make this dynamic with Claude
        return [
            ['headline' => 'Before: Manual Chaos', 'description' => 'Drowning in admin work'],
            ['headline' => 'After: AI Automation', 'description' => '90% time savings'],
            ['headline' => 'See The Difference', 'description' => 'Book free demo'],
        ];
    }

    /**
     * Format array for prompt
     */
    protected function formatArray(?array $arr): string
    {
        if (! $arr || empty($arr)) {
            return 'None specified';
        }

        return implode(', ', $arr);
    }

    /**
     * Extract text content from Anthropic API response
     */
    protected function extractTextFromResponse(array $response): string
    {
        // Handle direct text response
        if (isset($response['text'])) {
            return $response['text'];
        }

        // Handle content blocks array
        if (isset($response['content']) && is_array($response['content'])) {
            $text = '';
            foreach ($response['content'] as $block) {
                if (is_string($block)) {
                    $text .= $block;
                } elseif (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'] ?? '';
                }
            }

            return $text;
        }

        return '';
    }
}
