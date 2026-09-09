<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\WebsiteProject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebsiteBuilderSocialMediaAnalyzerTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Social Media Analyzer';
    }

    public function description(): string
    {
        return 'Analyze a business\'s social media presence across Facebook, Instagram, and X/Twitter. Extracts profile info, recent posts, engagement metrics, and content themes for use in website building.';
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
                    'description' => 'Business name for web search',
                ],
                'website_url' => [
                    'type' => 'string',
                    'description' => 'Client website to scrape for social links',
                ],
                'facebook_url' => [
                    'type' => 'string',
                    'description' => 'Facebook page URL',
                ],
                'instagram_handle' => [
                    'type' => 'string',
                    'description' => 'Instagram username (without @)',
                ],
                'twitter_handle' => [
                    'type' => 'string',
                    'description' => 'X/Twitter username (without @)',
                ],
                'linkedin_url' => [
                    'type' => 'string',
                    'description' => 'LinkedIn company page URL',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'nullable|integer|exists:website_projects,id',
            'business_name' => 'nullable|string|max:255',
            'website_url' => 'nullable|url',
            'facebook_url' => 'nullable|string|max:500',
            'instagram_handle' => 'nullable|string|max:100',
            'twitter_handle' => 'nullable|string|max:100',
            'linkedin_url' => 'nullable|string|max:500',
        ];
    }

    public function execute(array $params): array
    {
        $businessName = $params['business_name'] ?? null;
        $websiteUrl = $params['website_url'] ?? null;

        $socialLinks = [
            'facebook' => $params['facebook_url'] ?? null,
            'instagram' => $params['instagram_handle'] ?? null,
            'twitter' => $params['twitter_handle'] ?? null,
            'linkedin' => $params['linkedin_url'] ?? null,
        ];

        if ($websiteUrl && $this->hasEmptyLinks($socialLinks)) {
            $discoveredLinks = $this->discoverSocialLinksFromWebsite($websiteUrl);
            $socialLinks = array_merge($discoveredLinks, array_filter($socialLinks));
        }

        if ($businessName && $this->hasEmptyLinks($socialLinks)) {
            $searchedLinks = $this->searchForSocialProfiles($businessName);
            $socialLinks = array_merge($searchedLinks, array_filter($socialLinks));
        }

        $profiles = [];
        $topContent = [];
        $errors = [];

        if (! empty($socialLinks['facebook'])) {
            $fbResult = $this->analyzeFacebookPage($socialLinks['facebook']);
            if ($fbResult['success']) {
                $profiles['facebook'] = $fbResult['profile'];
                $topContent = array_merge($topContent, $fbResult['top_posts'] ?? []);
            } else {
                $errors['facebook'] = $fbResult['error'];
            }
        }

        if (! empty($socialLinks['instagram'])) {
            $igResult = $this->analyzeInstagramProfile($socialLinks['instagram']);
            if ($igResult['success']) {
                $profiles['instagram'] = $igResult['profile'];
                $topContent = array_merge($topContent, $igResult['top_posts'] ?? []);
            } else {
                $errors['instagram'] = $igResult['error'];
            }
        }

        if (! empty($socialLinks['twitter'])) {
            $xResult = $this->analyzeTwitterProfile($socialLinks['twitter']);
            if ($xResult['success']) {
                $profiles['twitter'] = $xResult['profile'];
                $topContent = array_merge($topContent, $xResult['top_posts'] ?? []);
            } else {
                $errors['twitter'] = $xResult['error'];
            }
        }

        $socialMediaData = [
            'analyzed_at' => now()->toIso8601String(),
            'social_links' => array_filter($socialLinks),
            'profiles' => $profiles,
            'top_content' => $this->rankContent($topContent),
            'content_themes' => $this->extractContentThemes($topContent),
            'posting_frequency' => $this->analyzePostingFrequency($profiles),
            'recommendations' => $this->generateRecommendations($profiles, $topContent),
        ];

        if (isset($params['project_id'])) {
            $project = WebsiteProject::find($params['project_id']);
            if ($project) {
                $project->update([
                    'source_data' => array_merge(
                        $project->source_data ?? [],
                        ['social_media' => $socialMediaData]
                    ),
                ]);
            }
        }

        return [
            'success' => count($profiles) > 0,
            'social_links' => array_filter($socialLinks),
            'profiles' => $profiles,
            'top_content' => array_slice($socialMediaData['top_content'], 0, 10),
            'content_themes' => $socialMediaData['content_themes'],
            'recommendations' => $socialMediaData['recommendations'],
            'errors' => $errors,
            'stored_in_project' => isset($params['project_id']),
        ];
    }

    protected function hasEmptyLinks(array $links): bool
    {
        return count(array_filter($links)) < 2;
    }

    protected function discoverSocialLinksFromWebsite(string $url): array
    {
        try {
            $response = Http::timeout(15)->get($url);

            if ($response->failed()) {
                return [];
            }

            $html = $response->body();
            $links = [];

            $patterns = [
                'facebook' => '/(?:facebook\.com|fb\.com)\/([a-zA-Z0-9._-]+)/i',
                'instagram' => '/instagram\.com\/([a-zA-Z0-9._]+)/i',
                'twitter' => '/(?:twitter\.com|x\.com)\/([a-zA-Z0-9_]+)/i',
                'linkedin' => '/linkedin\.com\/(?:company|in)\/([a-zA-Z0-9_-]+)/i',
                'youtube' => '/youtube\.com\/(?:channel|c|user)\/([a-zA-Z0-9_-]+)/i',
            ];

            foreach ($patterns as $platform => $pattern) {
                if (preg_match($pattern, $html, $matches)) {
                    $links[$platform] = $matches[1];
                }
            }

            return $links;
        } catch (\Exception $e) {
            Log::warning('Social link discovery failed', ['url' => $url, 'error' => $e->getMessage()]);

            return [];
        }
    }

    protected function searchForSocialProfiles(string $businessName): array
    {
        $apiKey = config('services.serper.api_key');

        if (! $apiKey) {
            return [];
        }

        try {
            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json',
            ])->post('https://google.serper.dev/search', [
                'q' => "{$businessName} facebook OR instagram OR twitter site:facebook.com OR site:instagram.com OR site:twitter.com",
                'num' => 10,
            ]);

            if ($response->failed()) {
                return [];
            }

            $results = $response->json()['organic'] ?? [];
            $links = [];

            foreach ($results as $result) {
                $url = $result['link'] ?? '';

                if (str_contains($url, 'facebook.com') && ! isset($links['facebook'])) {
                    if (preg_match('/facebook\.com\/([a-zA-Z0-9._-]+)/', $url, $m)) {
                        $links['facebook'] = $m[1];
                    }
                }
                if (str_contains($url, 'instagram.com') && ! isset($links['instagram'])) {
                    if (preg_match('/instagram\.com\/([a-zA-Z0-9._]+)/', $url, $m)) {
                        $links['instagram'] = $m[1];
                    }
                }
                if ((str_contains($url, 'twitter.com') || str_contains($url, 'x.com')) && ! isset($links['twitter'])) {
                    if (preg_match('/(?:twitter|x)\.com\/([a-zA-Z0-9_]+)/', $url, $m)) {
                        $links['twitter'] = $m[1];
                    }
                }
            }

            return $links;
        } catch (\Exception $e) {
            return [];
        }
    }

    protected function analyzeFacebookPage(string $pageIdOrUrl): array
    {
        $tool = new FacebookPageReviewsTool;
        $pageId = $this->extractFacebookPageId($pageIdOrUrl);

        $result = $tool->execute([
            'page_id' => $pageId,
            'include_posts' => true,
        ]);

        if (! $result['success']) {
            return $result;
        }

        $topPosts = collect($result['posts'] ?? [])
            ->map(fn ($post) => [
                'platform' => 'facebook',
                'content' => $post['message'] ?? '',
                'image' => $post['image'] ?? null,
                'url' => $post['url'] ?? null,
                'engagement' => ($post['reactions'] ?? 0) + ($post['shares'] ?? 0),
                'created_at' => $post['created_time'] ?? null,
            ])
            ->sortByDesc('engagement')
            ->take(5)
            ->values()
            ->toArray();

        return [
            'success' => true,
            'profile' => [
                'name' => $result['page']['name'] ?? null,
                'followers' => $result['page']['fan_count'] ?? 0,
                'rating' => $result['page']['rating'] ?? null,
                'category' => $result['page']['category'] ?? null,
                'url' => "https://facebook.com/{$pageId}",
                'profile_picture' => $result['page']['profile_picture'] ?? null,
                'cover_photo' => $result['page']['cover_photo'] ?? null,
            ],
            'top_posts' => $topPosts,
        ];
    }

    protected function analyzeInstagramProfile(string $handle): array
    {
        $handle = ltrim($handle, '@');

        return [
            'success' => true,
            'profile' => [
                'username' => $handle,
                'url' => "https://instagram.com/{$handle}",
                'note' => 'Instagram API requires business account connection. Profile discovered but detailed data requires API access.',
            ],
            'top_posts' => [],
        ];
    }

    protected function analyzeTwitterProfile(string $handle): array
    {
        $handle = ltrim($handle, '@');

        $xApiKey = config('services.x.api_key');

        if (! $xApiKey) {
            return [
                'success' => true,
                'profile' => [
                    'username' => $handle,
                    'url' => "https://x.com/{$handle}",
                    'note' => 'X/Twitter API key not configured. Profile discovered but detailed data requires API access.',
                ],
                'top_posts' => [],
            ];
        }

        return [
            'success' => true,
            'profile' => [
                'username' => $handle,
                'url' => "https://x.com/{$handle}",
            ],
            'top_posts' => [],
        ];
    }

    protected function extractFacebookPageId(string $input): string
    {
        if (preg_match('/facebook\.com\/([a-zA-Z0-9._-]+)/', $input, $matches)) {
            return $matches[1];
        }

        return $input;
    }

    protected function rankContent(array $content): array
    {
        return collect($content)
            ->filter(fn ($item) => ! empty($item['content']))
            ->sortByDesc('engagement')
            ->values()
            ->toArray();
    }

    protected function extractContentThemes(array $content): array
    {
        $allText = collect($content)->pluck('content')->filter()->implode(' ');

        if (empty($allText)) {
            return [];
        }

        $themes = [];
        $themePatterns = [
            'promotions' => '/(?:sale|discount|off|deal|special|offer|save|free)/i',
            'customer_stories' => '/(?:thank|customer|client|review|testimonial|feedback)/i',
            'behind_scenes' => '/(?:team|staff|behind|making|process|day in)/i',
            'tips_advice' => '/(?:tip|advice|how to|guide|learn|did you know)/i',
            'announcements' => '/(?:new|announce|launch|introducing|coming soon|excited)/i',
            'community' => '/(?:community|local|support|together|join us)/i',
            'seasonal' => '/(?:holiday|christmas|summer|spring|winter|fall|new year)/i',
        ];

        foreach ($themePatterns as $theme => $pattern) {
            if (preg_match($pattern, $allText)) {
                $themes[] = $theme;
            }
        }

        return $themes;
    }

    protected function analyzePostingFrequency(array $profiles): array
    {
        $frequency = [];

        foreach ($profiles as $platform => $profile) {
            if (isset($profile['posts_count']) && isset($profile['account_age_days'])) {
                $frequency[$platform] = round($profile['posts_count'] / max(1, $profile['account_age_days']) * 7, 1);
            }
        }

        return $frequency;
    }

    protected function generateRecommendations(array $profiles, array $content): array
    {
        $recommendations = [];

        if (empty($profiles)) {
            $recommendations[] = 'No social media profiles found. Consider creating business profiles on Facebook and Instagram.';

            return $recommendations;
        }

        $platforms = array_keys($profiles);

        if (! in_array('facebook', $platforms)) {
            $recommendations[] = 'Create a Facebook Business Page to reach local customers and collect reviews.';
        }

        if (! in_array('instagram', $platforms)) {
            $recommendations[] = 'Consider Instagram for visual content and reaching younger demographics.';
        }

        $topEngaging = collect($content)->sortByDesc('engagement')->first();
        if ($topEngaging && ! empty($topEngaging['content'])) {
            $recommendations[] = "Your best performing content focuses on: '{$this->truncate($topEngaging['content'], 100)}' - create more similar content.";
        }

        $themes = $this->extractContentThemes($content);
        if (! in_array('customer_stories', $themes)) {
            $recommendations[] = 'Share more customer testimonials and success stories on social media.';
        }

        return $recommendations;
    }

    protected function truncate(string $text, int $length): string
    {
        if (strlen($text) <= $length) {
            return $text;
        }

        return substr($text, 0, $length).'...';
    }
}
