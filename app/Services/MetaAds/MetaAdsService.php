<?php

namespace App\Services\MetaAds;

use App\Models\MetaAdAccount;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaAdsService
{
    protected string $apiVersion = 'v21.0';

    protected string $baseUrl;

    protected int $rateLimit = 200; // requests per hour

    protected int $requestsThisHour = 0;

    protected ?Carbon $rateLimitResetTime = null;

    public function __construct()
    {
        $this->baseUrl = "https://graph.facebook.com/{$this->apiVersion}";
    }

    /**
     * Get campaigns for an ad account
     */
    public function getCampaigns(MetaAdAccount $account, ?array $filters = []): Collection
    {
        $response = $this->makeRequest(
            'GET',
            "/{$account->account_id}/campaigns",
            [
                'fields' => 'id,name,objective,status,daily_budget,lifetime_budget,start_time,stop_time',
                ...$filters,
            ],
            $account
        );

        return collect($response['data'] ?? []);
    }

    /**
     * Get a single campaign by ID
     */
    public function getCampaign(string $campaignId, MetaAdAccount $account): ?array
    {
        try {
            return $this->makeRequest(
                'GET',
                "/{$campaignId}",
                [
                    'fields' => 'id,name,objective,status,daily_budget,lifetime_budget,start_time,stop_time,created_time,updated_time',
                ],
                $account
            );
        } catch (Exception $e) {
            Log::error("Failed to get campaign {$campaignId}", ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Create a new campaign
     */
    public function createCampaign(MetaAdAccount $account, array $params): string
    {
        $response = $this->makeRequest(
            'POST',
            "/{$account->account_id}/campaigns",
            $params,
            $account
        );

        return $response['id'];
    }

    /**
     * Update an existing campaign
     */
    public function updateCampaign(string $campaignId, array $params, MetaAdAccount $account): bool
    {
        try {
            $this->makeRequest(
                'POST',
                "/{$campaignId}",
                $params,
                $account
            );

            return true;
        } catch (Exception $e) {
            Log::error("Failed to update campaign {$campaignId}", ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Pause a campaign
     */
    public function pauseCampaign(string $campaignId, MetaAdAccount $account): bool
    {
        return $this->updateCampaign($campaignId, ['status' => 'PAUSED'], $account);
    }

    /**
     * Resume a campaign
     */
    public function resumeCampaign(string $campaignId, MetaAdAccount $account): bool
    {
        return $this->updateCampaign($campaignId, ['status' => 'ACTIVE'], $account);
    }

    /**
     * Delete a campaign from Meta
     */
    public function deleteCampaign(string $campaignId, MetaAdAccount $account): bool
    {
        try {
            $this->makeRequest(
                'DELETE',
                "/{$campaignId}",
                [],
                $account
            );

            return true;
        } catch (Exception $e) {
            Log::error("Failed to delete campaign {$campaignId}", ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Create an ad set
     */
    public function createAdSet(string $campaignId, array $params, MetaAdAccount $account): string
    {
        $response = $this->makeRequest(
            'POST',
            "/{$campaignId}/adsets",
            $params,
            $account
        );

        return $response['id'];
    }

    /**
     * Update ad set budget
     */
    public function updateAdSetBudget(string $adsetId, float $dailyBudget, MetaAdAccount $account): bool
    {
        try {
            $this->makeRequest(
                'POST',
                "/{$adsetId}",
                ['daily_budget' => (int) ($dailyBudget * 100)], // Meta expects cents
                $account
            );

            return true;
        } catch (Exception $e) {
            Log::error("Failed to update adset budget {$adsetId}", ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Pause an ad set
     */
    public function pauseAdSet(string $adsetId, MetaAdAccount $account): bool
    {
        try {
            $this->makeRequest(
                'POST',
                "/{$adsetId}",
                ['status' => 'PAUSED'],
                $account
            );

            return true;
        } catch (Exception $e) {
            Log::error("Failed to pause adset {$adsetId}", ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Create an ad
     */
    public function createAd(string $adsetId, array $params, MetaAdAccount $account): string
    {
        $response = $this->makeRequest(
            'POST',
            "/{$adsetId}/ads",
            $params,
            $account
        );

        return $response['id'];
    }

    /**
     * Pause an ad
     */
    public function pauseAd(string $adId, MetaAdAccount $account): bool
    {
        try {
            $this->makeRequest(
                'POST',
                "/{$adId}",
                ['status' => 'PAUSED'],
                $account
            );

            return true;
        } catch (Exception $e) {
            Log::error("Failed to pause ad {$adId}", ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Resume an ad
     */
    public function resumeAd(string $adId, MetaAdAccount $account): bool
    {
        try {
            $this->makeRequest(
                'POST',
                "/{$adId}",
                ['status' => 'ACTIVE'],
                $account
            );

            return true;
        } catch (Exception $e) {
            Log::error("Failed to resume ad {$adId}", ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Upload an image to Meta
     */
    public function uploadImage(MetaAdAccount $account, string $imageUrl): string
    {
        $response = $this->makeRequest(
            'POST',
            "/{$account->account_id}/adimages",
            [
                'url' => $imageUrl,
            ],
            $account
        );

        // Meta returns the hash in the 'images' object
        $images = $response['images'] ?? [];
        $firstImage = reset($images);

        return $firstImage['hash'] ?? '';
    }

    /**
     * Create an ad creative
     */
    public function createCreative(MetaAdAccount $account, array $params): string
    {
        $response = $this->makeRequest(
            'POST',
            "/{$account->account_id}/adcreatives",
            $params,
            $account
        );

        return $response['id'];
    }

    /**
     * Get a creative by ID
     */
    public function getCreative(string $creativeId, MetaAdAccount $account): ?array
    {
        try {
            return $this->makeRequest(
                'GET',
                "/{$creativeId}",
                [
                    'fields' => 'id,name,object_story_spec,thumbnail_url,image_hash,image_url',
                ],
                $account
            );
        } catch (Exception $e) {
            Log::error("Failed to get creative {$creativeId}", ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Get insights for any object (campaign, adset, ad)
     */
    public function getInsights(string $objectId, string $level, array $params, MetaAdAccount $account): array
    {
        $defaultParams = [
            'level' => $level,
            'fields' => 'impressions,clicks,spend,conversions,reach,frequency,ctr,cpc,cpm',
            'time_increment' => 1, // Daily
        ];

        $response = $this->makeRequest(
            'GET',
            "/{$objectId}/insights",
            array_merge($defaultParams, $params),
            $account
        );

        return $response['data'] ?? [];
    }

    /**
     * Get campaign insights
     */
    public function getCampaignInsights(string $campaignId, Carbon $since, Carbon $until, MetaAdAccount $account): array
    {
        return $this->getInsights(
            $campaignId,
            'campaign',
            [
                'time_range' => [
                    'since' => $since->format('Y-m-d'),
                    'until' => $until->format('Y-m-d'),
                ],
            ],
            $account
        );
    }

    /**
     * Get ad set insights
     */
    public function getAdSetInsights(string $adsetId, Carbon $since, Carbon $until, MetaAdAccount $account): array
    {
        return $this->getInsights(
            $adsetId,
            'adset',
            [
                'time_range' => [
                    'since' => $since->format('Y-m-d'),
                    'until' => $until->format('Y-m-d'),
                ],
            ],
            $account
        );
    }

    /**
     * Get ad insights
     */
    public function getAdInsights(string $adId, Carbon $since, Carbon $until, MetaAdAccount $account): array
    {
        return $this->getInsights(
            $adId,
            'ad',
            [
                'time_range' => [
                    'since' => $since->format('Y-m-d'),
                    'until' => $until->format('Y-m-d'),
                ],
            ],
            $account
        );
    }

    /**
     * Make an HTTP request to Meta API with rate limiting and error handling
     */
    protected function makeRequest(string $method, string $endpoint, array $params, MetaAdAccount $account): array
    {
        $this->checkRateLimit();

        $url = $this->baseUrl.$endpoint;
        $params['access_token'] = $account->access_token;

        try {
            $http = Http::timeout(30);

            $response = match ($method) {
                'GET' => $http->get($url, $params),
                'POST' => $http->post($url, $params),
                'DELETE' => $http->delete($url, $params),
                default => throw new Exception("Unsupported HTTP method: {$method}"),
            };

            $response->throw();
            $this->requestsThisHour++;

            return $response->json();
        } catch (Exception $e) {
            // Check if rate limit error
            if (str_contains($e->getMessage(), 'rate limit')) {
                $this->handleRateLimitError();

                // Retry after waiting
                return $this->makeRequest($method, $endpoint, $params, $account);
            }

            $errorData = [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
                'params_sent' => array_merge($params, ['access_token' => '***']),
            ];

            if (isset($response)) {
                $errorData['response_body'] = $response->body();
                $errorData['response_status'] = $response->status();
            }

            Log::error('Meta API request failed', $errorData);

            throw $e;
        }
    }

    /**
     * Check if we've hit the rate limit
     */
    protected function checkRateLimit(): void
    {
        if ($this->rateLimitResetTime === null) {
            $this->rateLimitResetTime = now()->addHour();
        }

        if (now()->greaterThan($this->rateLimitResetTime)) {
            $this->requestsThisHour = 0;
            $this->rateLimitResetTime = now()->addHour();
        }

        if ($this->requestsThisHour >= $this->rateLimit) {
            $waitSeconds = now()->diffInSeconds($this->rateLimitResetTime);
            Log::warning("Rate limit reached, waiting {$waitSeconds} seconds");
            sleep($waitSeconds);
            $this->requestsThisHour = 0;
            $this->rateLimitResetTime = now()->addHour();
        }
    }

    /**
     * Handle rate limit errors from Meta
     */
    protected function handleRateLimitError(): void
    {
        Log::warning('Meta API rate limit error, waiting 60 seconds');
        sleep(60);
        $this->requestsThisHour = 0;
        $this->rateLimitResetTime = now()->addHour();
    }
}
