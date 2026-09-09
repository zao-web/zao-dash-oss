<?php

namespace App\Mcp\Tools;

use App\Jobs\GenerateAdCreativesJob;
use App\Models\AdCampaign;
use App\Models\MetaAdAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateMetaCampaignTool extends Tool
{
    protected string $name = 'create-meta-campaign';

    protected string $title = 'Create Meta Ad Campaign';

    protected string $description = 'Create a new Meta (Facebook/Instagram) ad campaign with AI-generated creatives.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'objective' => 'required|in:OUTCOME_LEADS,OUTCOME_AWARENESS,OUTCOME_TRAFFIC,OUTCOME_ENGAGEMENT,OUTCOME_SALES',
            'daily_budget' => 'required|numeric|min:5',
            'destination_url' => 'required|url',
            'location_city' => 'required|string',
            'location_radius' => 'required|integer|min:1|max:50',
            'age_min' => 'required|integer|min:18|max:65',
            'age_max' => 'required|integer|min:18|max:65',
            'interests' => 'nullable|string',
            'target_cpa' => 'required|numeric|min:1',
            'cpa_max' => 'required|numeric|min:1',
            'ctr_min' => 'required|numeric|min:0.1',
            'automation_enabled' => 'nullable|boolean',
            'brand_guideline_id' => 'nullable|exists:brand_guidelines,id',
            'creative_count' => 'nullable|integer|min:1|max:5',
            'client_id' => 'nullable|exists:clients,id',
            'use_sandbox' => 'nullable|boolean',
        ]);

        // Get the appropriate ad account (sandbox or production)
        $adAccount = ($validated['use_sandbox'] ?? false)
            ? MetaAdAccount::sandbox()
            : MetaAdAccount::production();

        if (! $adAccount) {
            $accountType = ($validated['use_sandbox'] ?? false) ? 'sandbox' : 'production';

            return Response::error("No active Meta {$accountType} account found. Please connect your Meta account first.");
        }

        // Build targeting config
        $targetingConfig = [
            'location' => [
                'city' => $validated['location_city'],
                'radius_miles' => $validated['location_radius'],
            ],
            'age' => [
                'min' => $validated['age_min'],
                'max' => $validated['age_max'],
            ],
            'interests' => array_filter(array_map('trim', explode(',', $validated['interests'] ?? ''))),
        ];

        // Create campaign
        $campaign = AdCampaign::create([
            'meta_ad_account_id' => $adAccount->id,
            'client_id' => $validated['client_id'] ?? null,
            'name' => $validated['name'],
            'objective' => $validated['objective'],
            'status' => 'draft',
            'daily_budget' => $validated['daily_budget'],
            'start_date' => now(),
            'targeting_config' => $targetingConfig,
            'automation_enabled' => $validated['automation_enabled'] ?? true,
            'performance_goal' => [
                'target_cpa' => $validated['target_cpa'],
            ],
            'pause_threshold' => [
                'cpa_max' => $validated['cpa_max'],
                'ctr_min' => $validated['ctr_min'],
            ],
            'metadata' => [
                'destination_url' => $validated['destination_url'],
                'created_via' => 'mcp',
            ],
        ]);

        // Dispatch AI creative generation job
        $creativeCount = $validated['creative_count'] ?? 3;
        GenerateAdCreativesJob::dispatch(
            $campaign,
            $creativeCount,
            $validated['brand_guideline_id'] ?? null
        );

        return Response::structured([
            'campaign_id' => $campaign->id,
            'name' => $campaign->name,
            'objective' => $campaign->objective,
            'daily_budget' => $campaign->daily_budget,
            'status' => $campaign->status,
            'creative_count' => $creativeCount,
            'account_type' => $adAccount->is_sandbox ? 'sandbox' : 'production',
            'message' => "Campaign '{$campaign->name}' created successfully! AI is generating {$creativeCount} ad creatives now.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Campaign name'),
            'objective' => $schema->string()->required()->enum(['OUTCOME_LEADS', 'OUTCOME_AWARENESS', 'OUTCOME_TRAFFIC', 'OUTCOME_ENGAGEMENT', 'OUTCOME_SALES'])->description('Campaign objective'),
            'daily_budget' => $schema->number()->required()->description('Daily budget in USD (minimum $5)'),
            'destination_url' => $schema->string()->format('uri')->required()->description('Landing page URL'),
            'location_city' => $schema->string()->required()->description('City for geo-targeting (e.g., "Portland, OR")'),
            'location_radius' => $schema->integer()->required()->description('Radius in miles (1-50)'),
            'age_min' => $schema->integer()->required()->description('Minimum age (18-65)'),
            'age_max' => $schema->integer()->required()->description('Maximum age (18-65)'),
            'interests' => $schema->string()->description('Comma-separated interests (e.g., "small business, entrepreneurship")'),
            'target_cpa' => $schema->number()->required()->description('Target cost per acquisition/lead'),
            'cpa_max' => $schema->number()->required()->description('Maximum CPA threshold (campaign pauses if exceeded)'),
            'ctr_min' => $schema->number()->required()->description('Minimum CTR threshold (%)'),
            'automation_enabled' => $schema->boolean()->description('Enable AI optimization (default: true)'),
            'brand_guideline_id' => $schema->integer()->description('Brand guideline ID to use'),
            'creative_count' => $schema->integer()->description('Number of creative variations to generate (1-5, default: 3)'),
            'client_id' => $schema->integer()->description('Client ID to associate campaign with'),
            'use_sandbox' => $schema->boolean()->description('Use sandbox account for testing (default: false)'),
        ];
    }
}
