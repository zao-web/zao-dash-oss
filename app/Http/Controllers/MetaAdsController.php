<?php

namespace App\Http\Controllers;

use App\Models\AdCampaign;
use App\Models\AdPerformance;
use App\Models\MetaAdAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MetaAdsController extends Controller
{
    /**
     * Display the Meta Ads dashboard
     */
    public function index(): Response
    {
        // Get all Meta ad accounts
        $adAccounts = MetaAdAccount::where('status', 'active')->get();

        // Get all campaigns with performance data
        $campaigns = AdCampaign::with(['metaAdAccount', 'performance' => function ($query) {
            $query->orderBy('date', 'desc')->limit(1);
        }])
            ->whereIn('meta_ad_account_id', $adAccounts->pluck('id'))
            ->get()
            ->map(function ($campaign) {
                $latestPerformance = $campaign->performance->first();
                $statusInfo = $this->getStatusMessage($campaign, $latestPerformance);

                return [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'objective' => $campaign->objective,
                    'status' => $campaign->status,
                    'daily_budget' => $campaign->daily_budget,
                    'start_date' => $campaign->start_date?->format('M d, Y'),
                    'automation_enabled' => $campaign->automation_enabled,
                    'performance_goal' => $campaign->performance_goal,
                    'pause_threshold' => $campaign->pause_threshold,
                    'status_message' => $statusInfo['message'],
                    'status_type' => $statusInfo['type'],
                    'action_required' => $this->isActionable($campaign),
                    'is_sandbox' => $campaign->metaAdAccount->is_sandbox ?? false,
                    'performance' => $latestPerformance ? [
                        'spend' => $latestPerformance->spend,
                        'impressions' => $latestPerformance->impressions,
                        'clicks' => $latestPerformance->clicks,
                        'conversions' => $latestPerformance->conversions,
                        'ctr' => $latestPerformance->ctr,
                        'cpa' => $latestPerformance->cpa,
                    ] : null,
                    'adsets_count' => $campaign->adSets()->count(),
                    'ads_count' => $campaign->adSets()->withCount('ads')->get()->sum('ads_count'),
                ];
            });

        // Get summary metrics across all campaigns
        $totalSpend = $campaigns->sum(fn ($c) => $c['performance']['spend'] ?? 0);
        $totalImpressions = $campaigns->sum(fn ($c) => $c['performance']['impressions'] ?? 0);
        $totalClicks = $campaigns->sum(fn ($c) => $c['performance']['clicks'] ?? 0);
        $totalConversions = $campaigns->sum(fn ($c) => $c['performance']['conversions'] ?? 0);

        $avgCTR = $totalImpressions > 0
            ? round(($totalClicks / $totalImpressions) * 100, 2)
            : 0;

        $avgCPA = $totalConversions > 0
            ? round($totalSpend / $totalConversions, 2)
            : 0;

        return Inertia::render('MetaAds/Dashboard', [
            'campaigns' => $campaigns,
            'adAccounts' => $adAccounts->map(fn ($account) => [
                'id' => $account->id,
                'name' => $account->name,
                'account_id' => $account->account_id,
                'status' => $account->status,
                'currency' => $account->currency,
                'is_sandbox' => $account->is_sandbox ?? false,
            ]),
            'summary' => [
                'total_spend' => round($totalSpend, 2),
                'total_impressions' => $totalImpressions,
                'total_clicks' => $totalClicks,
                'total_conversions' => $totalConversions,
                'avg_ctr' => $avgCTR,
                'avg_cpa' => $avgCPA,
                'active_campaigns' => $campaigns->where('status', 'active')->count(),
                'pending_approval' => $campaigns->where('status', 'pending_approval')->count(),
            ],
        ]);
    }

    /**
     * Generate zero-cognitive-load status message
     */
    protected function getStatusMessage(AdCampaign $campaign, ?AdPerformance $performance): array
    {
        // Pending approval
        if ($campaign->status === 'pending_approval') {
            return [
                'message' => 'Waiting for your approval to launch',
                'type' => 'info',
            ];
        }

        // No performance data yet
        if (! $performance) {
            return [
                'message' => 'Just launched - gathering data',
                'type' => 'info',
            ];
        }

        // Check if winning (CPA below target)
        if ($campaign->hasReachedGoal()) {
            return [
                'message' => 'Winning - exceeding goals',
                'type' => 'success',
            ];
        }

        // Check if should pause (hitting thresholds)
        if ($campaign->shouldPause()) {
            return [
                'message' => 'Underperforming - needs attention',
                'type' => 'danger',
            ];
        }

        // Check CTR
        if ($performance->ctr < 1) {
            return [
                'message' => 'Testing - low engagement',
                'type' => 'warning',
            ];
        }

        // Default: performing normally
        return [
            'message' => 'Active - performing normally',
            'type' => 'success',
        ];
    }

    /**
     * Check if campaign requires user action
     */
    protected function isActionable(AdCampaign $campaign): bool
    {
        return $campaign->status === 'pending_approval' || $campaign->shouldPause();
    }

    /**
     * Show campaign details
     */
    public function show(AdCampaign $campaign): Response
    {
        $campaign->load(['metaAdAccount', 'adSets.ads.adCreative', 'performance']);

        // Get performance trend (last 14 days)
        $performanceTrend = $campaign->performance()
            ->where('date', '>=', now()->subDays(14))
            ->orderBy('date')
            ->get()
            ->map(fn ($p) => [
                'date' => $p->date->format('M d'),
                'spend' => $p->spend,
                'conversions' => $p->conversions,
                'cpa' => $p->cpa,
            ]);

        return Inertia::render('MetaAds/CampaignDetail', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'objective' => $campaign->objective,
                'status' => $campaign->status,
                'daily_budget' => $campaign->daily_budget,
                'performance_goal' => $campaign->performance_goal,
                'pause_threshold' => $campaign->pause_threshold,
                'automation_enabled' => $campaign->automation_enabled,
                'is_sandbox' => $campaign->metaAdAccount->is_sandbox,
                'creative_count' => $campaign->metadata['creative_count'] ?? 3,
                'failed_creatives' => $campaign->metadata['failed_creatives'] ?? [],
                'failure_reason' => $campaign->metadata['failure_reason'] ?? null,
                'adsets' => $campaign->adSets->map(fn ($adSet) => [
                    'id' => $adSet->id,
                    'name' => $adSet->name,
                    'status' => $adSet->status,
                    'daily_budget' => $adSet->daily_budget,
                    'ads_count' => $adSet->ads->count(),
                ]),
                'creatives' => $campaign->adSets->flatMap(fn ($adSet) => $adSet->ads->map(fn ($ad) => [
                    'id' => $ad->adCreative?->id,
                    'headline' => $ad->adCreative?->headline,
                    'primary_text' => $ad->adCreative?->primary_text,
                    'image_url' => $ad->adCreative?->image_url,
                    'status' => $ad->status,
                ]))->filter()->values(),
            ],
            'performanceTrend' => $performanceTrend,
        ]);
    }

    /**
     * Approve a pending campaign
     */
    public function approve(Request $request, AdCampaign $campaign)
    {
        if ($campaign->status !== 'pending_approval') {
            return back()->with('error', 'Campaign is not pending approval');
        }

        $campaign->update([
            'status' => 'active',
            'approved_at' => now(),
            'approved_by_user_id' => auth()->id(),
        ]);

        return back()->with('success', 'Campaign approved and activated');
    }

    /**
     * Pause a campaign
     */
    public function pause(AdCampaign $campaign)
    {
        $campaign->update(['status' => 'paused']);

        return back()->with('success', 'Campaign paused');
    }

    /**
     * Resume a campaign
     */
    public function resume(AdCampaign $campaign)
    {
        $campaign->update(['status' => 'active']);

        return back()->with('success', 'Campaign resumed');
    }

    /**
     * Show campaign creation form
     */
    public function create(): Response
    {
        $brandGuidelines = \App\Models\BrandGuideline::all();
        $clients = \App\Models\Client::where('status', 'active')->get(['id', 'name', 'slug']);

        return Inertia::render('MetaAds/CreateCampaign', [
            'brandGuidelines' => $brandGuidelines->map(fn ($bg) => [
                'id' => $bg->id,
                'name' => $bg->name,
                'brand_voice' => $bg->brand_voice,
            ]),
            'clients' => $clients,
            'hasSandboxAccount' => MetaAdAccount::sandbox() !== null,
            'hasProductionAccount' => MetaAdAccount::production() !== null,
        ]);
    }

    /**
     * Store a new campaign
     */
    public function store(Request $request)
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
            'automation_enabled' => 'boolean',
            'brand_guideline_id' => 'nullable|exists:brand_guidelines,id',
            'generate_creatives' => 'boolean',
            'creative_count' => 'required_if:generate_creatives,true|integer|min:1|max:5',
            'client_id' => 'nullable|exists:clients,id',
            'use_sandbox' => 'boolean',
        ]);

        // Get the appropriate ad account (sandbox or production)
        $adAccount = ($validated['use_sandbox'] ?? false)
            ? MetaAdAccount::sandbox()
            : MetaAdAccount::production();

        if (! $adAccount) {
            $accountType = ($validated['use_sandbox'] ?? false) ? 'sandbox' : 'production';

            return back()->with('error', "No active Meta {$accountType} account found. Please connect your Meta account first.");
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
            'client_id' => $validated['client_id'],
            'name' => $validated['name'],
            'objective' => $validated['objective'],
            'status' => 'draft', // Will be pending_approval after creatives generated
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
                'created_via' => 'dashboard',
            ],
        ]);

        // If generating creatives with AI, dispatch job
        if ($validated['generate_creatives'] ?? false) {
            \App\Jobs\GenerateAdCreativesJob::dispatch(
                $campaign,
                $validated['creative_count'],
                $validated['brand_guideline_id']
            );

            return redirect()
                ->route('meta-ads.campaigns.show', $campaign)
                ->with('success', 'Campaign created! AI is generating your ad creatives now. Watch for real-time updates.');
        }

        return redirect()
            ->route('meta-ads.campaigns.show', $campaign)
            ->with('success', 'Campaign created successfully! Add creatives to launch.');
    }

    /**
     * Delete a campaign
     */
    public function destroy(AdCampaign $campaign)
    {
        // Only allow deletion of draft, failed, or paused campaigns
        if (! in_array($campaign->status, ['draft', 'failed', 'paused'])) {
            return back()->with('error', 'Only draft, failed, or paused campaigns can be deleted. Please pause the campaign first.');
        }

        try {
            $campaignName = $campaign->name;

            // Delete from Meta API if it exists there
            if ($campaign->campaign_id) {
                try {
                    $metaAdsService = app(\App\Services\MetaAds\MetaAdsService::class);
                    $metaAdsService->deleteCampaign($campaign->campaign_id, $campaign->metaAdAccount);
                } catch (\Exception $e) {
                    Log::warning('Failed to delete campaign from Meta API, continuing with local deletion', [
                        'campaign_id' => $campaign->id,
                        'meta_campaign_id' => $campaign->campaign_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Clean up S3 images from creatives
            $creatives = \App\Models\AdCreative::whereHas('ads', function ($query) use ($campaign) {
                $query->whereHas('adSet', function ($q) use ($campaign) {
                    $q->where('ad_campaign_id', $campaign->id);
                });
            })->get();

            foreach ($creatives as $creative) {
                if ($creative->image_url) {
                    try {
                        // Extract path from URL
                        $path = parse_url($creative->image_url, PHP_URL_PATH);
                        if ($path && str_starts_with($path, '/storage/')) {
                            $storagePath = str_replace('/storage/', '', $path);
                            \Illuminate\Support\Facades\Storage::disk('public')->delete($storagePath);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete creative image from storage', [
                            'creative_id' => $creative->id,
                            'image_url' => $creative->image_url,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Delete campaign (cascade will delete adsets, ads, variants)
            $campaign->delete();

            return redirect()
                ->route('meta-ads.index')
                ->with('success', "Campaign \"{$campaignName}\" deleted successfully.");
        } catch (\Exception $e) {
            Log::error('Failed to delete campaign', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to delete campaign. Please try again.');
        }
    }
}
