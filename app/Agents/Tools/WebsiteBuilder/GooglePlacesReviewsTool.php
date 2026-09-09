<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Agents\Tools\Retryable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GooglePlacesReviewsTool extends BaseTool
{
    use Retryable;

    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Google Places Reviews';
    }

    public function description(): string
    {
        return 'Fetch Google Business reviews, ratings, and photos for a business. Use business name and location to find the Place ID, then retrieve reviews for testimonials and social proof sections.';
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
                    'description' => 'City, state or address to help locate the business',
                ],
                'place_id' => [
                    'type' => 'string',
                    'description' => 'Google Place ID if already known (skips search)',
                ],
            ],
            'required' => ['business_name'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'business_name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'place_id' => 'nullable|string|max:255',
        ];
    }

    public function execute(array $params): array
    {
        $apiKey = config('services.google.places_api_key');

        if (! $apiKey) {
            return [
                'success' => false,
                'error' => 'Google Places API key not configured. Set GOOGLE_PLACES_API_KEY in environment.',
                'reviews' => [],
            ];
        }

        $businessName = $params['business_name'];
        $location = $params['location'] ?? '';
        $placeId = $params['place_id'] ?? null;

        try {
            if (! $placeId) {
                $placeId = $this->findPlaceId($businessName, $location, $apiKey);

                if (! $placeId) {
                    return [
                        'success' => false,
                        'error' => "Could not find Google Place ID for '{$businessName}' in '{$location}'",
                        'reviews' => [],
                    ];
                }
            }

            $details = $this->getPlaceDetails($placeId, $apiKey);

            if (! $details) {
                return [
                    'success' => false,
                    'error' => 'Failed to fetch place details',
                    'place_id' => $placeId,
                    'reviews' => [],
                ];
            }

            $reviews = $this->formatReviews($details['reviews'] ?? []);

            return [
                'success' => true,
                'place_id' => $placeId,
                'business' => [
                    'name' => $details['name'] ?? $businessName,
                    'address' => $details['formatted_address'] ?? null,
                    'phone' => $details['formatted_phone_number'] ?? null,
                    'website' => $details['website'] ?? null,
                    'rating' => $details['rating'] ?? null,
                    'total_ratings' => $details['user_ratings_total'] ?? 0,
                    'hours' => $details['opening_hours']['weekday_text'] ?? [],
                    'google_url' => $details['url'] ?? null,
                ],
                'reviews' => $reviews,
                'photos' => $this->formatPhotos($details['photos'] ?? [], $apiKey),
                'review_summary' => [
                    'average_rating' => $details['rating'] ?? null,
                    'total_reviews' => $details['user_ratings_total'] ?? 0,
                    'five_star_reviews' => collect($reviews)->where('rating', 5)->count(),
                    'recent_reviews' => collect($reviews)->take(5)->count(),
                ],
            ];
        } catch (\Exception $e) {
            Log::error('GooglePlacesReviewsTool error', [
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

    protected function findPlaceId(string $businessName, string $location, string $apiKey): ?string
    {
        $query = $location ? "{$businessName} {$location}" : $businessName;

        $response = Http::get('https://maps.googleapis.com/maps/api/place/findplacefromtext/json', [
            'input' => $query,
            'inputtype' => 'textquery',
            'fields' => 'place_id,name,formatted_address',
            'key' => $apiKey,
        ]);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();
        $candidates = $data['candidates'] ?? [];

        return $candidates[0]['place_id'] ?? null;
    }

    protected function getPlaceDetails(string $placeId, string $apiKey): ?array
    {
        $response = Http::get('https://maps.googleapis.com/maps/api/place/details/json', [
            'place_id' => $placeId,
            'fields' => implode(',', [
                'name',
                'formatted_address',
                'formatted_phone_number',
                'website',
                'rating',
                'user_ratings_total',
                'reviews',
                'photos',
                'opening_hours',
                'url',
                'price_level',
                'types',
            ]),
            'key' => $apiKey,
        ]);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();

        return $data['result'] ?? null;
    }

    protected function formatReviews(array $reviews): array
    {
        return collect($reviews)
            ->map(function ($review) {
                return [
                    'author' => $review['author_name'] ?? 'Anonymous',
                    'author_url' => $review['author_url'] ?? null,
                    'profile_photo' => $review['profile_photo_url'] ?? null,
                    'rating' => $review['rating'] ?? 5,
                    'text' => $review['text'] ?? '',
                    'time' => $review['time'] ?? null,
                    'relative_time' => $review['relative_time_description'] ?? null,
                    'source' => 'google',
                    'short_quote' => $this->extractShortQuote($review['text'] ?? ''),
                ];
            })
            ->sortByDesc('rating')
            ->values()
            ->toArray();
    }

    protected function formatPhotos(array $photos, string $apiKey): array
    {
        return collect($photos)
            ->take(10)
            ->map(function ($photo) use ($apiKey) {
                $reference = $photo['photo_reference'] ?? null;
                if (! $reference) {
                    return null;
                }

                return [
                    'url' => "https://maps.googleapis.com/maps/api/place/photo?maxwidth=800&photo_reference={$reference}&key={$apiKey}",
                    'width' => $photo['width'] ?? null,
                    'height' => $photo['height'] ?? null,
                    'attributions' => $photo['html_attributions'] ?? [],
                ];
            })
            ->filter()
            ->values()
            ->toArray();
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
