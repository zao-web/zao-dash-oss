<?php

namespace App\Jobs;

use App\Events\CampaignCreativeGenerationCompleted;
use App\Events\CampaignCreativeGenerationProgress;
use App\Events\CampaignCreativeGenerationStarted;
use App\Models\Ad;
use App\Models\AdCampaign;
use App\Models\AdCreative;
use App\Models\AdSet;
use App\Models\BrandGuideline;
use App\Services\MetaAds\AdCreativeGenerationService;
use App\Services\MetaAds\MetaAdsService;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GenerateAdCreativesJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 600;

    public function __construct(
        public AdCampaign $campaign,
        public int $creativeCount,
        public ?int $brandGuidelineId = null,
    ) {}

    /**
     * Execute the job - Generate ad creatives and push to Meta API
     */
    public function handle(
        AdCreativeGenerationService $creativeService,
        MetaAdsService $metaAdsService
    ): void {
        try {
            // Broadcast start
            event(new CampaignCreativeGenerationStarted($this->campaign, $this->creativeCount));

            // Step 1: Load or create brand guidelines
            $brandGuideline = $this->getBrandGuideline();

            event(new CampaignCreativeGenerationProgress(
                $this->campaign,
                'brand_guidelines',
                'Loaded brand guidelines',
                1,
                $this->creativeCount + 3, // Total steps: brand + creatives + adset + meta_push
            ));

            // Step 2: Generate copy variations using Claude
            // Use campaign name and objective to generate relevant copy
            $baseHeadline = $this->campaign->name;
            $basePrimaryText = $this->getBasePrimaryTextFromObjective($this->campaign->objective);

            $copyVariations = $creativeService->generateVariations(
                $baseHeadline,
                $basePrimaryText,
                $brandGuideline,
                $this->creativeCount
            );

            event(new CampaignCreativeGenerationProgress(
                $this->campaign,
                'copy_generation',
                "Generated {$this->creativeCount} copy variations with Claude",
                2,
                $this->creativeCount + 3,
            ));

            // Step 3: Generate images and create creatives
            $creatives = [];
            $failedCreatives = [];

            foreach ($copyVariations as $index => $variation) {
                try {
                    $currentImage = $index + 1;
                    event(new CampaignCreativeGenerationProgress(
                        $this->campaign,
                        'image_generation',
                        "Generating image {$currentImage}/{$this->creativeCount} with Nano Banana Pro...",
                        $index + 3,
                        $this->creativeCount + 3,
                        ['variation' => $variation['angle']],
                    ));

                    // Generate image using Nano Banana Pro
                    $imageUrl = $creativeService->generateAdImage(
                        $brandGuideline,
                        $variation['headline'],
                        $variation['primary_text']
                    );

                    // Create AdCreative record
                    $creative = AdCreative::create([
                        'meta_ad_account_id' => $this->campaign->meta_ad_account_id,
                        'name' => "{$this->campaign->name} - Variant ".chr(65 + $index),
                        'type' => 'image',
                        'headline' => $variation['headline'],
                        'primary_text' => $variation['primary_text'],
                        'description' => $variation['description'] ?? null,
                        'image_url' => $imageUrl,
                        'brand_guideline_id' => $brandGuideline->id,
                        'generation_prompt' => "Angle: {$variation['angle']}",
                        'generation_metadata' => [
                            'model' => 'dall-e-3',
                            'provider' => 'openai',
                            'model_name' => 'DALL-E 3',
                            'angle' => $variation['angle'],
                            'generated_at' => now()->toIso8601String(),
                        ],
                    ]);

                    $creatives[] = $creative;

                    $currentImage = $index + 1;
                    event(new CampaignCreativeGenerationProgress(
                        $this->campaign,
                        'creative_saved',
                        "Creative {$currentImage}/{$this->creativeCount} ready: {$variation['headline']}",
                        $index + 3,
                        $this->creativeCount + 3,
                        [
                            'creative_index' => $index,
                            'creative_id' => $creative->id,
                            'headline' => $creative->headline,
                            'primary_text' => $creative->primary_text,
                            'description' => $creative->description,
                            'image_url' => $creative->image_url,
                            'variant_name' => chr(65 + $index),
                        ],
                    ));
                } catch (Exception $e) {
                    $currentImage = $index + 1;
                    $errorMessage = $e->getMessage();

                    Log::error("Failed to generate creative {$currentImage}", [
                        'campaign_id' => $this->campaign->id,
                        'variation' => $variation,
                        'error' => $errorMessage,
                    ]);

                    // Track failed creative
                    $failedCreatives[] = [
                        'index' => $index,
                        'variant_name' => chr(65 + $index),
                        'headline' => $variation['headline'] ?? 'Unknown',
                        'error' => $errorMessage,
                    ];

                    // Broadcast failure for this creative
                    event(new CampaignCreativeGenerationProgress(
                        $this->campaign,
                        'creative_failed',
                        "Creative {$currentImage}/{$this->creativeCount} failed: {$errorMessage}",
                        $index + 3,
                        $this->creativeCount + 3,
                        [
                            'creative_index' => $index,
                            'variant_name' => chr(65 + $index),
                            'error' => $errorMessage,
                        ],
                    ));

                    // Continue with other creatives even if one fails
                    continue;
                }
            }

            // If ALL creatives failed, mark campaign as failed
            if (empty($creatives)) {
                $this->campaign->update([
                    'status' => 'failed',
                    'metadata' => array_merge($this->campaign->metadata ?? [], [
                        'failed_creatives' => $failedCreatives,
                        'failure_reason' => 'All creatives failed to generate',
                    ]),
                ]);

                throw new Exception('No creatives were generated successfully. All '.count($failedCreatives).' attempts failed.');
            }

            // If some creatives succeeded, save failed ones to metadata for visibility
            if (! empty($failedCreatives)) {
                $this->campaign->update([
                    'metadata' => array_merge($this->campaign->metadata ?? [], [
                        'failed_creatives' => $failedCreatives,
                    ]),
                ]);

                Log::warning('Some creatives failed during generation', [
                    'campaign_id' => $this->campaign->id,
                    'succeeded' => count($creatives),
                    'failed' => count($failedCreatives),
                ]);
            }

            // Step 4: Create AdSet
            event(new CampaignCreativeGenerationProgress(
                $this->campaign,
                'adset_creation',
                'Creating ad set with targeting...',
                $this->creativeCount + 1,
                $this->creativeCount + 3,
            ));

            $adSet = $this->createAdSet();

            // Step 5: Create Ads linking to creatives
            foreach ($creatives as $index => $creative) {
                Ad::create([
                    'ad_set_id' => $adSet->id,
                    'ad_creative_id' => $creative->id,
                    'name' => $creative->name,
                    'status' => 'draft',
                    'creative_type' => 'single_image',
                    'call_to_action' => 'LEARN_MORE',
                    'destination_url' => $this->campaign->metadata['destination_url'] ?? 'https://example.com/ai',
                ]);
            }

            // Step 6: Push to Meta API (sandbox)
            event(new CampaignCreativeGenerationProgress(
                $this->campaign,
                'meta_api_push',
                'Pushing to Meta Ads API...',
                $this->creativeCount + 2,
                $this->creativeCount + 3,
            ));

            $this->pushToMetaAPI($metaAdsService, $adSet, $creatives);

            // Mark campaign as pending approval
            $this->campaign->update(['status' => 'pending_approval']);

            // Broadcast completion
            event(new CampaignCreativeGenerationCompleted(
                $this->campaign,
                count($creatives),
                true,
                null,
                [
                    'adset_id' => $adSet->id,
                    'ad_ids' => $adSet->ads->pluck('id')->toArray(),
                    'creative_ids' => collect($creatives)->pluck('id')->toArray(),
                ],
            ));
        } catch (Exception $e) {
            Log::error('Ad creative generation failed', [
                'campaign_id' => $this->campaign->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Mark campaign as failed
            $this->campaign->update(['status' => 'failed']);

            // Broadcast failure
            event(new CampaignCreativeGenerationCompleted(
                $this->campaign,
                0,
                false,
                $e->getMessage(),
            ));

            throw $e;
        }
    }

    /**
     * Get or create brand guideline
     */
    protected function getBrandGuideline(): BrandGuideline
    {
        if ($this->brandGuidelineId) {
            return BrandGuideline::findOrFail($this->brandGuidelineId);
        }

        // Create default brand guideline
        return BrandGuideline::firstOrCreate(
            ['client_id' => $this->campaign->client_id, 'name' => 'Default Brand Guidelines'],
            [
                'primary_colors' => ['#0ea5e9', '#f59e0b'], // Sky blue + Amber
                'secondary_colors' => ['#64748b', '#f8fafc'],
                'fonts' => ['primary' => 'Inter', 'secondary' => 'Merriweather'],
                'brand_voice' => 'Professional, technical, approachable',
                'tone' => 'Confident but not salesy',
                'keywords_to_include' => ['workflow', 'automation', 'efficiency', 'AI'],
                'keywords_to_avoid' => ['cheap', 'easy', 'simple', 'quick fix'],
                'imagery_style' => 'Clean, modern, minimal, professional',
            ]
        );
    }

    /**
     * Create AdSet with campaign targeting
     */
    protected function createAdSet(): AdSet
    {
        $targeting = $this->campaign->targeting_config;

        return AdSet::create([
            'ad_campaign_id' => $this->campaign->id,
            'name' => "{$this->campaign->name} - Primary Audience",
            'status' => 'draft',
            'daily_budget' => $this->campaign->daily_budget,
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'targeting' => [
                'geo_locations' => [
                    'custom_locations' => [
                        [
                            'latitude' => 45.3001,
                            'longitude' => -122.9732,
                            'radius' => $targeting['location']['radius_miles'],
                            'distance_unit' => 'mile',
                        ],
                    ],
                ],
                'age_min' => $targeting['age']['min'],
                'age_max' => $targeting['age']['max'],
            ],
            'optimization_goal' => 'LEAD_GENERATION',
            'billing_event' => 'IMPRESSIONS',
        ]);
    }

    /**
     * Push campaign, adset, ads, and creatives to Meta API
     */
    protected function pushToMetaAPI(MetaAdsService $metaAdsService, AdSet $adSet, array $creatives): void
    {
        $account = $this->campaign->metaAdAccount;
        $currentStep = 'initialization';
        $currentPayload = [];

        try {
            // 1. Create campaign in Meta
            $currentStep = 'create_campaign';
            $currentPayload = [
                'name' => $this->campaign->name,
                'objective' => $this->campaign->objective,
                'status' => 'PAUSED',
                'special_ad_categories' => [],
            ];
            Log::info('Creating Meta campaign', ['step' => $currentStep, 'payload' => $currentPayload]);

            $metaCampaignId = $metaAdsService->createCampaign($account, $currentPayload);
            $this->campaign->update(['campaign_id' => $metaCampaignId]);

            // 2. Create adset in Meta
            $currentStep = 'create_adset';
            $currentPayload = [
                'name' => $adSet->name,
                'campaign_id' => $metaCampaignId,
                'daily_budget' => $adSet->daily_budget * 100,
                'billing_event' => $adSet->billing_event,
                'optimization_goal' => $adSet->optimization_goal,
                'bid_strategy' => $adSet->bid_strategy,
                'targeting' => $adSet->targeting,
                'status' => 'PAUSED',
            ];
            Log::info('Creating Meta adset', ['step' => $currentStep, 'payload' => $currentPayload]);

            $metaAdSetId = $metaAdsService->createAdSet($metaCampaignId, $currentPayload);

            $adSet->update(['adset_id' => $metaAdSetId]);

            // 3. Upload images and create creatives in Meta
            foreach ($creatives as $creative) {
                // Upload image to Meta
                $currentStep = 'upload_image';
                $currentPayload = ['image_url' => $creative->image_url];
                Log::info('Uploading image to Meta', ['step' => $currentStep, 'creative_id' => $creative->id]);

                $imageHash = $metaAdsService->uploadImage($account, $creative->image_url);
                $creative->update(['image_hash' => $imageHash]);

                // Create creative in Meta
                $currentStep = 'create_creative';
                $currentPayload = [
                    'name' => $creative->name,
                    'object_story_spec' => [
                        'page_id' => $account->metadata['page_id'] ?? null,
                        'link_data' => [
                            'image_hash' => $imageHash,
                            'link' => $this->campaign->metadata['destination_url'] ?? 'https://example.com/ai',
                            'message' => $creative->primary_text,
                            'name' => $creative->headline,
                            'description' => $creative->description,
                            'call_to_action' => [
                                'type' => 'LEARN_MORE',
                            ],
                        ],
                    ],
                ];
                Log::info('Creating Meta creative', ['step' => $currentStep, 'payload' => $currentPayload]);

                $metaCreativeId = $metaAdsService->createCreative($account, $currentPayload);
                $creative->update(['creative_id' => $metaCreativeId]);

                // 4. Create ad in Meta
                $ad = $adSet->ads()->where('ad_creative_id', $creative->id)->first();
                if ($ad) {
                    $currentStep = 'create_ad';
                    $currentPayload = [
                        'name' => $ad->name,
                        'adset_id' => $metaAdSetId,
                        'creative' => ['creative_id' => $metaCreativeId],
                        'status' => 'PAUSED',
                    ];
                    Log::info('Creating Meta ad', ['step' => $currentStep, 'payload' => $currentPayload]);

                    $metaAdId = $metaAdsService->createAd($metaAdSetId, $currentPayload);
                    $ad->update(['ad_id' => $metaAdId]);
                }
            }
        } catch (Exception $e) {
            Log::error('Failed to push to Meta API', [
                'campaign_id' => $this->campaign->id,
                'failed_step' => $currentStep,
                'payload_sent' => $currentPayload,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            throw new Exception("Meta API push failed at step '{$currentStep}': {$e->getMessage()}");
        }
    }

    /**
     * Generate base primary text based on campaign objective
     */
    protected function getBasePrimaryTextFromObjective(string $objective): string
    {
        $destinationUrl = $this->campaign->metadata['destination_url'] ?? '';
        $domain = $destinationUrl ? parse_url($destinationUrl, PHP_URL_HOST) : 'your business';

        return match ($objective) {
            'OUTCOME_LEADS' => "Transform your workflow with {$domain}. Book a consultation today.",
            'OUTCOME_AWARENESS' => "Discover how {$domain} is changing the industry.",
            'OUTCOME_TRAFFIC' => "Visit {$domain} to learn more about our solutions.",
            'OUTCOME_ENGAGEMENT' => 'Join the conversation about the future of work.',
            'OUTCOME_SALES' => "Get started with {$domain} today.",
            default => "Discover the power of {$domain}.",
        };
    }
}
