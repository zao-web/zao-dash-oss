<?php

namespace App\Jobs;

use App\Models\Ad;
use App\Models\AdCampaign;
use App\Models\AdPerformance;
use App\Models\AdSet;
use App\Models\MetaAdAccount;
use App\Services\MetaAds\MetaAdsService;
use Carbon\Carbon;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncMetaAdsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ?int $metaAdAccountId = null
    ) {}

    /**
     * Execute the job - Sync performance data from Meta API
     */
    public function handle(MetaAdsService $metaAdsService): void
    {
        $accounts = $this->metaAdAccountId
            ? MetaAdAccount::where('id', $this->metaAdAccountId)->get()
            : MetaAdAccount::where('status', 'active')->get();

        foreach ($accounts as $account) {
            try {
                $this->syncAccount($account, $metaAdsService);
            } catch (Exception $e) {
                Log::error("Failed to sync Meta Ad Account #{$account->id}", [
                    'error' => $e->getMessage(),
                    'account_id' => $account->account_id,
                ]);
            }
        }
    }

    /**
     * Sync a single ad account
     */
    protected function syncAccount(MetaAdAccount $account, MetaAdsService $service): void
    {
        Log::info("Syncing Meta Ad Account #{$account->id} ({$account->account_id})");

        // Get date range (last 7 days to fill any gaps)
        $since = now()->subDays(7);
        $until = now()->subDay(); // Yesterday (today's data may be incomplete)

        // Sync campaigns
        $this->syncCampaigns($account, $service, $since, $until);

        // Update sync timestamp
        $account->update(['metadata' => array_merge($account->metadata ?? [], [
            'last_synced_at' => now()->toIso8601String(),
        ])]);

        Log::info("Completed sync for Meta Ad Account #{$account->id}");
    }

    /**
     * Sync campaigns for an account
     */
    protected function syncCampaigns(
        MetaAdAccount $account,
        MetaAdsService $service,
        Carbon $since,
        Carbon $until
    ): void {
        $campaigns = $account->adCampaigns()->whereNotNull('campaign_id')->get();

        foreach ($campaigns as $campaign) {
            try {
                // Get campaign insights from Meta
                $insights = $service->getCampaignInsights(
                    $campaign->campaign_id,
                    $since,
                    $until,
                    $account
                );

                // Store performance data
                foreach ($insights as $insight) {
                    $this->storePerformance($campaign, $insight, 'AdCampaign');
                }

                // Sync ad sets
                $this->syncAdSets($campaign, $service, $since, $until, $account);

                // Mark as synced
                $campaign->update(['synced_at' => now()]);
            } catch (Exception $e) {
                Log::warning("Failed to sync campaign #{$campaign->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Sync ad sets for a campaign
     */
    protected function syncAdSets(
        AdCampaign $campaign,
        MetaAdsService $service,
        Carbon $since,
        Carbon $until,
        MetaAdAccount $account
    ): void {
        $adSets = $campaign->adSets()->whereNotNull('adset_id')->get();

        foreach ($adSets as $adSet) {
            try {
                $insights = $service->getAdSetInsights(
                    $adSet->adset_id,
                    $since,
                    $until,
                    $account
                );

                foreach ($insights as $insight) {
                    $this->storePerformance($adSet, $insight, 'AdSet');
                }

                // Sync ads
                $this->syncAds($adSet, $service, $since, $until, $account);

                $adSet->update(['synced_at' => now()]);
            } catch (Exception $e) {
                Log::warning("Failed to sync ad set #{$adSet->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Sync ads for an ad set
     */
    protected function syncAds(
        AdSet $adSet,
        MetaAdsService $service,
        Carbon $since,
        Carbon $until,
        MetaAdAccount $account
    ): void {
        $ads = $adSet->ads()->whereNotNull('ad_id')->get();

        foreach ($ads as $ad) {
            try {
                $insights = $service->getAdInsights(
                    $ad->ad_id,
                    $since,
                    $until,
                    $account
                );

                foreach ($insights as $insight) {
                    $this->storePerformance($ad, $insight, 'Ad');
                }

                $ad->update(['synced_at' => now()]);
            } catch (Exception $e) {
                Log::warning("Failed to sync ad #{$ad->id}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Store performance data
     */
    protected function storePerformance($model, array $insight, string $modelType): void
    {
        $date = isset($insight['date_start']) ? Carbon::parse($insight['date_start']) : now()->subDay();

        $performance = AdPerformance::updateOrCreate(
            [
                'performable_type' => "App\\Models\\{$modelType}",
                'performable_id' => $model->id,
                'date' => $date,
                'hour' => null, // Daily granularity
            ],
            [
                'impressions' => $insight['impressions'] ?? 0,
                'clicks' => $insight['clicks'] ?? 0,
                'spend' => $insight['spend'] ?? 0,
                'conversions' => $this->extractConversions($insight),
                'conversion_value' => $this->extractConversionValue($insight),
                'reach' => $insight['reach'] ?? null,
                'frequency' => $insight['frequency'] ?? null,
                'metadata' => $insight,
                'synced_at' => now(),
            ]
        );

        // Update computed metrics (CTR, CPC, CPA, ROAS)
        $performance->updateComputedMetrics();
    }

    /**
     * Extract conversions from insight data
     */
    protected function extractConversions(array $insight): int
    {
        // Meta returns conversions in various formats
        if (isset($insight['conversions'])) {
            return (int) $insight['conversions'];
        }

        if (isset($insight['actions'])) {
            foreach ($insight['actions'] as $action) {
                if (in_array($action['action_type'], ['lead', 'purchase', 'complete_registration'])) {
                    return (int) $action['value'];
                }
            }
        }

        return 0;
    }

    /**
     * Extract conversion value from insight data
     */
    protected function extractConversionValue(array $insight): float
    {
        if (isset($insight['conversion_values'])) {
            return (float) $insight['conversion_values'];
        }

        if (isset($insight['action_values'])) {
            foreach ($insight['action_values'] as $actionValue) {
                if (in_array($actionValue['action_type'], ['purchase', 'lead_value'])) {
                    return (float) $actionValue['value'];
                }
            }
        }

        return 0;
    }
}
