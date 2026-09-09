<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\WebsiteProject;
use Illuminate\Support\Facades\Log;

class WebsiteBuilderSocialProofAggregatorTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Social Proof Aggregator';
    }

    public function description(): string
    {
        return 'Aggregate reviews and testimonials from Google, Facebook, Yelp, and the client\'s existing website. Consolidates into a unified format for building testimonial sections. Stores results in project social_proof field.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Website project ID to store results',
                ],
                'business_name' => [
                    'type' => 'string',
                    'description' => 'Business name to search for',
                ],
                'location' => [
                    'type' => 'string',
                    'description' => 'City, state for location-based search',
                ],
                'website_url' => [
                    'type' => 'string',
                    'description' => 'Client website URL to scrape for existing testimonials',
                ],
                'google_place_id' => [
                    'type' => 'string',
                    'description' => 'Google Place ID if known',
                ],

                'yelp_business_id' => [
                    'type' => 'string',
                    'description' => 'Yelp Business ID if known',
                ],
                'sources' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Specific sources to query: google, yelp, website (default: all)',
                ],
                'min_rating' => [
                    'type' => 'number',
                    'description' => 'Minimum rating to include (default: 4)',
                ],
            ],
            'required' => ['business_name'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'nullable|integer|exists:website_projects,id',
            'business_name' => 'required|string|max:255',
            'location' => 'nullable|string|max:255',
            'website_url' => 'nullable|url',
            'google_place_id' => 'nullable|string|max:255',

            'yelp_business_id' => 'nullable|string|max:255',
            'sources' => 'nullable|array',
            'sources.*' => 'in:google,yelp,website',
            'min_rating' => 'nullable|numeric|min:1|max:5',
        ];
    }

    public function execute(array $params): array
    {
        $businessName = $params['business_name'];
        $location = $params['location'] ?? '';
        $websiteUrl = $params['website_url'] ?? null;
        $sources = $params['sources'] ?? ['google', 'yelp', 'website'];
        $minRating = $params['min_rating'] ?? 4;

        $allReviews = [];
        $sourceResults = [];
        $errors = [];

        if (in_array('google', $sources)) {
            $googleResult = $this->fetchGoogleReviews($params);
            $sourceResults['google'] = $googleResult;
            if ($googleResult['success']) {
                $allReviews = array_merge($allReviews, $googleResult['reviews'] ?? []);
            } else {
                $errors['google'] = $googleResult['error'] ?? 'Unknown error';
            }
        }

        if (in_array('yelp', $sources)) {
            $yelpResult = $this->fetchYelpReviews($params);
            $sourceResults['yelp'] = $yelpResult;
            if ($yelpResult['success']) {
                $allReviews = array_merge($allReviews, $yelpResult['reviews'] ?? []);
            } else {
                $errors['yelp'] = $yelpResult['error'] ?? 'Unknown error';
            }
        }

        if (in_array('website', $sources) && $websiteUrl) {
            $websiteResult = $this->scrapeWebsiteTestimonials($websiteUrl);
            $sourceResults['website'] = $websiteResult;
            if ($websiteResult['success']) {
                $allReviews = array_merge($allReviews, $websiteResult['reviews'] ?? []);
            } else {
                $errors['website'] = $websiteResult['error'] ?? 'Unknown error';
            }
        }

        $filteredReviews = $this->filterAndRankReviews($allReviews, $minRating);
        $topTestimonials = $this->selectTopTestimonials($filteredReviews, 10);
        $businessInfo = $this->consolidateBusinessInfo($sourceResults);

        $socialProofData = [
            'business_name' => $businessName,
            'location' => $location,
            'collected_at' => now()->toIso8601String(),
            'sources_queried' => $sources,
            'source_errors' => $errors,
            'business_info' => $businessInfo,
            'all_reviews' => $filteredReviews,
            'top_testimonials' => $topTestimonials,
            'summary' => [
                'total_reviews' => count($allReviews),
                'filtered_reviews' => count($filteredReviews),
                'average_rating' => $this->calculateAverageRating($filteredReviews),
                'by_source' => $this->countBySource($filteredReviews),
                'five_star_count' => collect($filteredReviews)->where('rating', 5)->count(),
            ],
            'photos' => $this->collectPhotos($sourceResults),
        ];

        if (isset($params['project_id'])) {
            $project = WebsiteProject::find($params['project_id']);
            if ($project) {
                $project->update([
                    'source_data' => array_merge(
                        $project->source_data ?? [],
                        ['social_proof' => $socialProofData]
                    ),
                ]);
            }
        }

        return [
            'success' => count($filteredReviews) > 0 || count($errors) < count($sources),
            'business_name' => $businessName,
            'summary' => $socialProofData['summary'],
            'top_testimonials' => $topTestimonials,
            'business_info' => $businessInfo,
            'photos' => $socialProofData['photos'],
            'errors' => $errors,
            'stored_in_project' => isset($params['project_id']),
        ];
    }

    protected function fetchGoogleReviews(array $params): array
    {
        $tool = new GooglePlacesReviewsTool;

        return $tool->execute([
            'business_name' => $params['business_name'],
            'location' => $params['location'] ?? '',
            'place_id' => $params['google_place_id'] ?? null,
        ]);
    }

    protected function fetchYelpReviews(array $params): array
    {
        $tool = new YelpReviewsTool;

        return $tool->execute([
            'business_name' => $params['business_name'],
            'location' => $params['location'] ?? '',
            'business_id' => $params['yelp_business_id'] ?? null,
        ]);
    }

    protected function scrapeWebsiteTestimonials(string $url): array
    {
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(30)->get($url);

            if ($response->failed()) {
                return [
                    'success' => false,
                    'error' => 'Failed to fetch website',
                    'reviews' => [],
                ];
            }

            $html = $response->body();
            $testimonials = $this->extractTestimonialsFromHtml($html);

            return [
                'success' => true,
                'reviews' => $testimonials,
            ];
        } catch (\Exception $e) {
            Log::warning('Website testimonial scrape failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'reviews' => [],
            ];
        }
    }

    protected function extractTestimonialsFromHtml(string $html): array
    {
        $testimonials = [];

        $patterns = [
            '/<blockquote[^>]*class="[^"]*testimonial[^"]*"[^>]*>(.*?)<\/blockquote>/is',
            '/<div[^>]*class="[^"]*(?:testimonial|review|quote)[^"]*"[^>]*>(.*?)<\/div>/is',
            '/<p[^>]*class="[^"]*(?:testimonial|review-text|quote)[^"]*"[^>]*>(.*?)<\/p>/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $match) {
                    $text = strip_tags($match);
                    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
                    $text = preg_replace('/\s+/', ' ', $text);
                    $text = trim($text);

                    if (strlen($text) > 20 && strlen($text) < 2000) {
                        $testimonials[] = [
                            'author' => $this->extractAuthorFromContext($match, $html),
                            'text' => $text,
                            'rating' => null,
                            'source' => 'website',
                            'short_quote' => $this->extractShortQuote($text),
                        ];
                    }
                }
            }
        }

        return array_slice($testimonials, 0, 20);
    }

    protected function extractAuthorFromContext(string $match, string $html): string
    {
        $authorPatterns = [
            '/<(?:cite|span|p|div)[^>]*class="[^"]*(?:author|name|customer)[^"]*"[^>]*>(.*?)<\/(?:cite|span|p|div)>/is',
            '/(?:—|–|-)\s*([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)/s',
        ];

        foreach ($authorPatterns as $pattern) {
            if (preg_match($pattern, $match, $authorMatch)) {
                $author = strip_tags($authorMatch[1]);
                $author = trim($author, " \t\n\r\0\x0B—–-");
                if (strlen($author) > 2 && strlen($author) < 100) {
                    return $author;
                }
            }
        }

        return 'Customer';
    }

    protected function filterAndRankReviews(array $reviews, float $minRating): array
    {
        return collect($reviews)
            ->filter(function ($review) use ($minRating) {
                $rating = $review['rating'] ?? 5;

                return $rating >= $minRating && ! empty($review['text']);
            })
            ->map(function ($review) {
                $review['score'] = $this->calculateReviewScore($review);

                return $review;
            })
            ->sortByDesc('score')
            ->values()
            ->toArray();
    }

    protected function calculateReviewScore(array $review): float
    {
        $score = 0;

        $rating = $review['rating'] ?? 5;
        $score += $rating * 20;

        $textLength = strlen($review['text'] ?? '');
        if ($textLength > 50 && $textLength < 500) {
            $score += 30;
        } elseif ($textLength >= 500) {
            $score += 20;
        }

        if (! empty($review['author']) && $review['author'] !== 'Anonymous' && $review['author'] !== 'Customer') {
            $score += 10;
        }

        if (! empty($review['profile_photo'])) {
            $score += 10;
        }

        $sourceBonus = match ($review['source'] ?? 'unknown') {
            'google' => 15,
            'yelp' => 12,
            'facebook' => 10,
            'website' => 5,
            default => 0,
        };
        $score += $sourceBonus;

        return $score;
    }

    protected function selectTopTestimonials(array $reviews, int $count): array
    {
        $selected = [];
        $sources = [];

        foreach ($reviews as $review) {
            if (count($selected) >= $count) {
                break;
            }

            $source = $review['source'] ?? 'unknown';
            $sourceCount = $sources[$source] ?? 0;

            if ($sourceCount < ceil($count / 3)) {
                $selected[] = [
                    'author' => $review['author'] ?? 'Customer',
                    'text' => $review['text'],
                    'short_quote' => $review['short_quote'] ?? $this->extractShortQuote($review['text']),
                    'rating' => $review['rating'] ?? 5,
                    'source' => $source,
                    'profile_photo' => $review['profile_photo'] ?? null,
                ];
                $sources[$source] = $sourceCount + 1;
            }
        }

        while (count($selected) < $count && count($selected) < count($reviews)) {
            foreach ($reviews as $review) {
                $isDuplicate = collect($selected)->contains('text', $review['text']);
                if (! $isDuplicate) {
                    $selected[] = [
                        'author' => $review['author'] ?? 'Customer',
                        'text' => $review['text'],
                        'short_quote' => $review['short_quote'] ?? $this->extractShortQuote($review['text']),
                        'rating' => $review['rating'] ?? 5,
                        'source' => $review['source'] ?? 'unknown',
                        'profile_photo' => $review['profile_photo'] ?? null,
                    ];
                    if (count($selected) >= $count) {
                        break;
                    }
                }
            }
            break;
        }

        return $selected;
    }

    protected function consolidateBusinessInfo(array $sourceResults): array
    {
        $info = [
            'name' => null,
            'address' => null,
            'phone' => null,
            'website' => null,
            'hours' => [],
            'ratings' => [],
        ];

        foreach (['google', 'yelp', 'facebook'] as $source) {
            $data = $sourceResults[$source] ?? [];
            $business = $data['business'] ?? $data['page'] ?? [];

            if (! $info['name'] && ! empty($business['name'])) {
                $info['name'] = $business['name'];
            }
            if (! $info['address'] && ! empty($business['address'])) {
                $info['address'] = $business['address'];
            }
            if (! $info['phone'] && ! empty($business['phone'])) {
                $info['phone'] = $business['phone'];
            }
            if (! $info['website'] && ! empty($business['website'])) {
                $info['website'] = $business['website'];
            }
            if (empty($info['hours']) && ! empty($business['hours'])) {
                $info['hours'] = $business['hours'];
            }

            if (! empty($business['rating'])) {
                $info['ratings'][$source] = [
                    'rating' => $business['rating'],
                    'count' => $business['total_ratings'] ?? $business['review_count'] ?? $business['rating_count'] ?? 0,
                ];
            }
        }

        return $info;
    }

    protected function calculateAverageRating(array $reviews): ?float
    {
        $ratingsWithValues = collect($reviews)->filter(fn ($r) => isset($r['rating']) && $r['rating'] > 0);

        if ($ratingsWithValues->isEmpty()) {
            return null;
        }

        return round($ratingsWithValues->avg('rating'), 1);
    }

    protected function countBySource(array $reviews): array
    {
        return collect($reviews)
            ->groupBy('source')
            ->map(fn ($group) => count($group))
            ->toArray();
    }

    protected function collectPhotos(array $sourceResults): array
    {
        $photos = [];

        if (! empty($sourceResults['google']['photos'])) {
            foreach (array_slice($sourceResults['google']['photos'], 0, 5) as $photo) {
                $photos[] = array_merge($photo, ['source' => 'google']);
            }
        }

        if (! empty($sourceResults['yelp']['business']['photos'])) {
            foreach (array_slice($sourceResults['yelp']['business']['photos'], 0, 5) as $url) {
                $photos[] = ['url' => $url, 'source' => 'yelp'];
            }
        }

        if (! empty($sourceResults['facebook']['page']['cover_photo'])) {
            $photos[] = [
                'url' => $sourceResults['facebook']['page']['cover_photo'],
                'source' => 'facebook',
            ];
        }

        return $photos;
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
