<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\Retryable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YelpReviewsTool extends BaseTool
{
    use Retryable;

    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Yelp Reviews';
    }

    public function description(): string
    {
        return 'Fetch Yelp business reviews, ratings, and photos using the Yelp Fusion API. Search by business name and location or use a known Yelp business ID.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'business_name' => [
                    'type' => 'string',
                    'description' => 'Name of the business to search for',
                ],
                'location' => [
                    'type' => 'string',
                    'description' => 'City, state or address',
                ],
                'business_id' => [
                    'type' => 'string',
                    'description' => 'Yelp business ID if already known',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'business_name' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'business_id' => 'nullable|string|max:255',
        ];
    }

    public function execute(array $params): array
    {
        $apiKey = config('services.yelp.api_key');

        if (! $apiKey) {
            return [
                'success' => false,
                'error' => 'Yelp API key not configured. Set YELP_API_KEY in environment.',
                'reviews' => [],
            ];
        }

        $businessName = $params['business_name'] ?? null;
        $location = $params['location'] ?? null;
        $businessId = $params['business_id'] ?? null;

        try {
            if (! $businessId) {
                if (! $businessName) {
                    return [
                        'success' => false,
                        'error' => 'Either business_id or business_name is required',
                        'reviews' => [],
                    ];
                }

                $businessId = $this->searchBusiness($businessName, $location, $apiKey);

                if (! $businessId) {
                    return [
                        'success' => false,
                        'error' => "Could not find Yelp business: {$businessName} in {$location}",
                        'reviews' => [],
                    ];
                }
            }

            $details = $this->getBusinessDetails($businessId, $apiKey);

            if (! $details) {
                return [
                    'success' => false,
                    'error' => 'Failed to fetch business details',
                    'business_id' => $businessId,
                    'reviews' => [],
                ];
            }

            $reviews = $this->getBusinessReviews($businessId, $apiKey);

            return [
                'success' => true,
                'business_id' => $businessId,
                'business' => [
                    'name' => $details['name'] ?? $businessName,
                    'url' => $details['url'] ?? null,
                    'phone' => $details['display_phone'] ?? null,
                    'address' => $this->formatAddress($details['location'] ?? []),
                    'rating' => $details['rating'] ?? null,
                    'review_count' => $details['review_count'] ?? 0,
                    'price' => $details['price'] ?? null,
                    'categories' => collect($details['categories'] ?? [])->pluck('title')->toArray(),
                    'hours' => $this->formatHours($details['hours'] ?? []),
                    'photos' => array_slice($details['photos'] ?? [], 0, 10),
                    'is_claimed' => $details['is_claimed'] ?? false,
                ],
                'reviews' => $this->formatReviews($reviews),
                'review_summary' => [
                    'average_rating' => $details['rating'] ?? null,
                    'total_reviews' => $details['review_count'] ?? 0,
                    'five_star_reviews' => collect($reviews)->where('rating', 5)->count(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('YelpReviewsTool error', [
                'business' => $businessName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'reviews' => [],
            ];
        }
    }

    protected function searchBusiness(string $name, ?string $location, string $apiKey): ?string
    {
        $params = [
            'term' => $name,
            'limit' => 1,
        ];

        if ($location) {
            $params['location'] = $location;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
        ])->get('https://api.yelp.com/v3/businesses/search', $params);

        if ($response->failed()) {
            return null;
        }

        $businesses = $response->json()['businesses'] ?? [];

        return $businesses[0]['id'] ?? null;
    }

    protected function getBusinessDetails(string $businessId, string $apiKey): ?array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
        ])->get("https://api.yelp.com/v3/businesses/{$businessId}");

        if ($response->failed()) {
            return null;
        }

        return $response->json();
    }

    protected function getBusinessReviews(string $businessId, string $apiKey): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
        ])->get("https://api.yelp.com/v3/businesses/{$businessId}/reviews", [
            'limit' => 50,
            'sort_by' => 'yelp_sort',
        ]);

        if ($response->failed()) {
            return [];
        }

        return $response->json()['reviews'] ?? [];
    }

    protected function formatReviews(array $reviews): array
    {
        return collect($reviews)
            ->map(function ($review) {
                $user = $review['user'] ?? [];

                return [
                    'author' => $user['name'] ?? 'Yelp User',
                    'author_url' => $user['profile_url'] ?? null,
                    'profile_photo' => $user['image_url'] ?? null,
                    'rating' => $review['rating'] ?? 5,
                    'text' => $review['text'] ?? '',
                    'time' => $review['time_created'] ?? null,
                    'url' => $review['url'] ?? null,
                    'source' => 'yelp',
                    'short_quote' => $this->extractShortQuote($review['text'] ?? ''),
                ];
            })
            ->sortByDesc('rating')
            ->values()
            ->toArray();
    }

    protected function formatAddress(array $location): ?string
    {
        $displayAddress = $location['display_address'] ?? [];

        return implode(', ', $displayAddress) ?: null;
    }

    protected function formatHours(array $hours): array
    {
        if (empty($hours) || empty($hours[0]['open'])) {
            return [];
        }

        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $formatted = [];

        foreach ($hours[0]['open'] as $slot) {
            $day = $days[$slot['day']] ?? '';
            $start = substr_replace($slot['start'], ':', 2, 0);
            $end = substr_replace($slot['end'], ':', 2, 0);
            $formatted[] = "{$day}: {$start} - {$end}";
        }

        return $formatted;
    }

    protected function extractShortQuote(string $text, int $maxLength = 150): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        $truncated = substr($text, 0, $maxLength);
        $lastPeriod = strrpos($truncated, '.');
        $lastExclaim = strrpos($truncated, '!');
        $lastBreak = max($lastPeriod ?: 0, $lastExclaim ?: 0);

        if ($lastBreak > $maxLength * 0.5) {
            return substr($text, 0, $lastBreak + 1);
        }

        $lastSpace = strrpos($truncated, ' ');

        return substr($text, 0, $lastSpace ?: $maxLength).'...';
    }
}
