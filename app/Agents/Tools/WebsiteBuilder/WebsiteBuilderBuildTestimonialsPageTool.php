<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\WebsiteProject;

class WebsiteBuilderBuildTestimonialsPageTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Build Testimonials Page';
    }

    public function description(): string
    {
        return 'Generate a testimonials/reviews page using aggregated social proof data. Creates WordPress block markup with testimonial patterns from Ollie theme. Requires running SocialProofAggregatorTool first.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Website project ID with social_proof data',
                ],
                'page_title' => [
                    'type' => 'string',
                    'description' => 'Page title (default: "What Our Customers Say")',
                ],
                'include_rating_summary' => [
                    'type' => 'boolean',
                    'description' => 'Include aggregate rating display at top',
                ],
                'include_review_sources' => [
                    'type' => 'boolean',
                    'description' => 'Show which platforms reviews came from',
                ],
                'max_testimonials' => [
                    'type' => 'integer',
                    'description' => 'Maximum testimonials to display (default: 12)',
                ],
                'layout' => [
                    'type' => 'string',
                    'enum' => ['grid', 'carousel', 'list', 'featured'],
                    'description' => 'Layout style for testimonials',
                ],
            ],
            'required' => ['project_id'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'page_title' => 'nullable|string|max:255',
            'include_rating_summary' => 'nullable|boolean',
            'include_review_sources' => 'nullable|boolean',
            'max_testimonials' => 'nullable|integer|min:1|max:50',
            'layout' => 'nullable|in:grid,carousel,list,featured',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::find($params['project_id']);

        if (! $project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        $socialProof = $project->source_data['social_proof'] ?? null;

        if (! $socialProof) {
            return [
                'success' => false,
                'error' => 'No social proof data found. Run SocialProofAggregatorTool first.',
            ];
        }

        $pageTitle = $params['page_title'] ?? 'What Our Customers Say';
        $includeRatingSummary = $params['include_rating_summary'] ?? true;
        $includeReviewSources = $params['include_review_sources'] ?? true;
        $maxTestimonials = $params['max_testimonials'] ?? 12;
        $layout = $params['layout'] ?? 'grid';

        $testimonials = array_slice($socialProof['top_testimonials'] ?? [], 0, $maxTestimonials);
        $summary = $socialProof['summary'] ?? [];
        $businessInfo = $socialProof['business_info'] ?? [];

        $blocks = [];

        $blocks[] = $this->buildHeroSection($pageTitle, $businessInfo, $summary);

        if ($includeRatingSummary && ! empty($summary['average_rating'])) {
            $blocks[] = $this->buildRatingSummarySection($summary, $businessInfo);
        }

        $blocks[] = $this->buildTestimonialsSection($testimonials, $layout);

        if ($includeReviewSources) {
            $blocks[] = $this->buildReviewSourcesSection($summary['by_source'] ?? [], $businessInfo);
        }

        $blocks[] = $this->buildCtaSection($businessInfo);

        $pageContent = implode("\n\n", $blocks);

        $pages = $project->pages ?? [];
        $pages['testimonials'] = [
            'title' => $pageTitle,
            'slug' => 'testimonials',
            'template' => 'page-no-title',
            'content' => $pageContent,
            'menu_order' => 4,
            'created_at' => now()->toIso8601String(),
        ];

        $project->update(['pages' => $pages]);

        return [
            'success' => true,
            'page_slug' => 'testimonials',
            'testimonials_count' => count($testimonials),
            'content_length' => strlen($pageContent),
            'sections' => ['hero', 'rating_summary', 'testimonials', 'sources', 'cta'],
        ];
    }

    protected function buildHeroSection(string $title, array $businessInfo, array $summary): string
    {
        $businessName = $businessInfo['name'] ?? 'Our Business';
        $totalReviews = $summary['total_reviews'] ?? 0;
        $avgRating = $summary['average_rating'] ?? null;

        $subtitle = $avgRating
            ? "Rated {$avgRating}/5 based on {$totalReviews} reviews"
            : "See why customers love {$businessName}";

        return <<<BLOCKS
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large","left":"var:preset|spacing|medium","right":"var:preset|spacing|medium"}}},"backgroundColor":"tertiary","layout":{"type":"constrained","contentSize":"800px"}} -->
<div class="wp-block-group alignfull has-tertiary-background-color has-background" style="padding-top:var(--wp--preset--spacing--x-large);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--x-large);padding-left:var(--wp--preset--spacing--medium)">

<!-- wp:heading {"textAlign":"center","level":1} -->
<h1 class="wp-block-heading has-text-align-center">{$title}</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"1.25rem"}},"textColor":"secondary"} -->
<p class="has-text-align-center has-secondary-color has-text-color" style="font-size:1.25rem">{$subtitle}</p>
<!-- /wp:paragraph -->

</div>
<!-- /wp:group -->
BLOCKS;
    }

    protected function buildRatingSummarySection(array $summary, array $businessInfo): string
    {
        $avgRating = $summary['average_rating'] ?? 0;
        $totalReviews = $summary['total_reviews'] ?? 0;
        $fiveStarCount = $summary['five_star_count'] ?? 0;
        $ratings = $businessInfo['ratings'] ?? [];

        $stars = $this->generateStarRating($avgRating);

        $platformRatings = '';
        foreach ($ratings as $platform => $data) {
            $platformStars = $this->generateStarRating($data['rating'] ?? 0);
            $count = $data['count'] ?? 0;
            $platformName = ucfirst($platform);
            $platformRatings .= <<<BLOCK

<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|small","bottom":"var:preset|spacing|small"}}},"layout":{"type":"flex","justifyContent":"center","flexWrap":"nowrap"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--small);padding-bottom:var(--wp--preset--spacing--small)">
<!-- wp:paragraph {"style":{"typography":{"fontSize":"0.9rem"}},"textColor":"secondary"} -->
<p class="has-secondary-color has-text-color" style="font-size:0.9rem"><strong>{$platformName}:</strong> {$platformStars} ({$count} reviews)</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
BLOCK;
        }

        return <<<BLOCKS
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|large","bottom":"var:preset|spacing|large","left":"var:preset|spacing|medium","right":"var:preset|spacing|medium"}}},"backgroundColor":"base","layout":{"type":"constrained","contentSize":"600px"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--large);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--large);padding-left:var(--wp--preset--spacing--medium)">

<!-- wp:group {"style":{"border":{"radius":"12px"},"spacing":{"padding":{"top":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium","left":"var:preset|spacing|medium","right":"var:preset|spacing|medium"}}},"backgroundColor":"tertiary","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-tertiary-background-color has-background" style="border-radius:12px;padding-top:var(--wp--preset--spacing--medium);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--medium);padding-left:var(--wp--preset--spacing--medium)">

<!-- wp:heading {"textAlign":"center","level":2,"style":{"typography":{"fontSize":"3rem"}}} -->
<h2 class="wp-block-heading has-text-align-center" style="font-size:3rem">{$avgRating}/5</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"1.5rem"}}} -->
<p class="has-text-align-center" style="font-size:1.5rem">{$stars}</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"align":"center","textColor":"secondary"} -->
<p class="has-text-align-center has-secondary-color has-text-color">Based on {$totalReviews} reviews • {$fiveStarCount} five-star ratings</p>
<!-- /wp:paragraph -->

{$platformRatings}

</div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->
BLOCKS;
    }

    protected function buildTestimonialsSection(array $testimonials, string $layout): string
    {
        if (empty($testimonials)) {
            return '';
        }

        $testimonialBlocks = match ($layout) {
            'featured' => $this->buildFeaturedLayout($testimonials),
            'list' => $this->buildListLayout($testimonials),
            'carousel' => $this->buildGridLayout($testimonials),
            default => $this->buildGridLayout($testimonials),
        };

        return <<<BLOCKS
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large","left":"var:preset|spacing|medium","right":"var:preset|spacing|medium"}}},"backgroundColor":"base","layout":{"type":"constrained","contentSize":"1200px"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--x-large);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--x-large);padding-left:var(--wp--preset--spacing--medium)">

<!-- wp:heading {"textAlign":"center","style":{"spacing":{"margin":{"bottom":"var:preset|spacing|large"}}}} -->
<h2 class="wp-block-heading has-text-align-center" style="margin-bottom:var(--wp--preset--spacing--large)">Customer Reviews</h2>
<!-- /wp:heading -->

{$testimonialBlocks}

</div>
<!-- /wp:group -->
BLOCKS;
    }

    protected function buildGridLayout(array $testimonials): string
    {
        $cards = [];

        foreach (array_chunk($testimonials, 3) as $row) {
            $columns = [];

            foreach ($row as $testimonial) {
                $columns[] = $this->buildTestimonialCard($testimonial);
            }

            $columnsHtml = implode("\n\n", $columns);
            $cards[] = <<<ROW
<!-- wp:columns {"style":{"spacing":{"blockGap":{"left":"var:preset|spacing|medium"},"margin":{"bottom":"var:preset|spacing|medium"}}}} -->
<div class="wp-block-columns" style="margin-bottom:var(--wp--preset--spacing--medium)">
{$columnsHtml}
</div>
<!-- /wp:columns -->
ROW;
        }

        return implode("\n\n", $cards);
    }

    protected function buildFeaturedLayout(array $testimonials): string
    {
        $blocks = [];

        if (! empty($testimonials[0])) {
            $featured = $testimonials[0];
            $text = htmlspecialchars($featured['text'] ?? '', ENT_QUOTES);
            $author = htmlspecialchars($featured['author'] ?? 'Customer', ENT_QUOTES);
            $stars = $this->generateStarRating($featured['rating'] ?? 5);

            $blocks[] = <<<FEATURED
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|large","bottom":"var:preset|spacing|large","left":"var:preset|spacing|large","right":"var:preset|spacing|large"},"margin":{"bottom":"var:preset|spacing|large"}},"border":{"radius":"12px"}},"backgroundColor":"primary","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-primary-background-color has-background" style="border-radius:12px;margin-bottom:var(--wp--preset--spacing--large);padding-top:var(--wp--preset--spacing--large);padding-right:var(--wp--preset--spacing--large);padding-bottom:var(--wp--preset--spacing--large);padding-left:var(--wp--preset--spacing--large)">

<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"1.5rem","fontStyle":"italic"}},"textColor":"base"} -->
<p class="has-text-align-center has-base-color has-text-color" style="font-size:1.5rem;font-style:italic">"{$text}"</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"align":"center","textColor":"main-accent"} -->
<p class="has-text-align-center has-main-accent-color has-text-color">{$stars}</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"align":"center","style":{"typography":{"fontWeight":"600"}},"textColor":"base"} -->
<p class="has-text-align-center has-base-color has-text-color" style="font-weight:600">— {$author}</p>
<!-- /wp:paragraph -->

</div>
<!-- /wp:group -->
FEATURED;
        }

        $remaining = array_slice($testimonials, 1);
        if (! empty($remaining)) {
            $blocks[] = $this->buildGridLayout($remaining);
        }

        return implode("\n\n", $blocks);
    }

    protected function buildListLayout(array $testimonials): string
    {
        $items = [];

        foreach ($testimonials as $testimonial) {
            $text = htmlspecialchars($testimonial['text'] ?? '', ENT_QUOTES);
            $author = htmlspecialchars($testimonial['author'] ?? 'Customer', ENT_QUOTES);
            $stars = $this->generateStarRating($testimonial['rating'] ?? 5);
            $source = ucfirst($testimonial['source'] ?? 'review');

            $items[] = <<<ITEM
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium"}},"border":{"bottom":{"color":"var:preset|color|border-light","width":"1px"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="border-bottom-color:var(--wp--preset--color--border-light);border-bottom-width:1px;padding-top:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--medium)">

<!-- wp:paragraph {"textColor":"secondary"} -->
<p class="has-secondary-color has-text-color">"{$text}"</p>
<!-- /wp:paragraph -->

<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between"}} -->
<div class="wp-block-group">
<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}},"textColor":"main"} -->
<p class="has-main-color has-text-color" style="font-weight:600">— {$author}</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"style":{"typography":{"fontSize":"0.85rem"}},"textColor":"secondary"} -->
<p class="has-secondary-color has-text-color" style="font-size:0.85rem">{$stars} via {$source}</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->
ITEM;
        }

        return implode("\n\n", $items);
    }

    protected function buildTestimonialCard(array $testimonial): string
    {
        $text = htmlspecialchars($testimonial['short_quote'] ?? $testimonial['text'] ?? '', ENT_QUOTES);
        $author = htmlspecialchars($testimonial['author'] ?? 'Customer', ENT_QUOTES);
        $stars = $this->generateStarRating($testimonial['rating'] ?? 5);
        $source = ucfirst($testimonial['source'] ?? 'review');

        return <<<CARD
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium","left":"var:preset|spacing|medium","right":"var:preset|spacing|medium"}},"border":{"radius":"8px","width":"1px"}},"borderColor":"border-light","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-border-color has-border-light-border-color" style="border-width:1px;border-radius:8px;padding-top:var(--wp--preset--spacing--medium);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--medium);padding-left:var(--wp--preset--spacing--medium)">

<!-- wp:paragraph {"textColor":"secondary"} -->
<p class="has-secondary-color has-text-color">"{$text}"</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"typography":{"fontWeight":"600"}},"textColor":"main"} -->
<p class="has-main-color has-text-color" style="font-weight:600">— {$author}</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"typography":{"fontSize":"0.85rem"}},"textColor":"secondary"} -->
<p class="has-secondary-color has-text-color" style="font-size:0.85rem">{$stars} • {$source}</p>
<!-- /wp:paragraph -->

</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
CARD;
    }

    protected function buildReviewSourcesSection(array $bySource, array $businessInfo): string
    {
        $sourceLogos = [];
        $ratings = $businessInfo['ratings'] ?? [];

        foreach (['google', 'yelp', 'facebook'] as $source) {
            $count = $bySource[$source] ?? 0;
            $rating = $ratings[$source]['rating'] ?? null;

            if ($count > 0 || $rating) {
                $label = ucfirst($source);
                $ratingText = $rating ? "{$rating}★" : '';
                $countText = $count > 0 ? "{$count} reviews" : '';
                $details = implode(' • ', array_filter([$ratingText, $countText]));

                $sourceLogos[] = <<<SOURCE
<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|small","bottom":"var:preset|spacing|small","left":"var:preset|spacing|medium","right":"var:preset|spacing|medium"}},"border":{"radius":"8px"}},"backgroundColor":"tertiary","layout":{"type":"constrained"}} -->
<div class="wp-block-group has-tertiary-background-color has-background" style="border-radius:8px;padding-top:var(--wp--preset--spacing--small);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--small);padding-left:var(--wp--preset--spacing--medium)">
<!-- wp:paragraph {"align":"center","textColor":"main"} -->
<p class="has-text-align-center has-main-color has-text-color"><strong>{$label}</strong></p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"0.85rem"}},"textColor":"secondary"} -->
<p class="has-text-align-center has-secondary-color has-text-color" style="font-size:0.85rem">{$details}</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
SOURCE;
            }
        }

        if (empty($sourceLogos)) {
            return '';
        }

        $sourcesHtml = implode("\n", $sourceLogos);

        return <<<BLOCKS
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|large","bottom":"var:preset|spacing|large","left":"var:preset|spacing|medium","right":"var:preset|spacing|medium"}}},"backgroundColor":"base","layout":{"type":"constrained","contentSize":"800px"}} -->
<div class="wp-block-group alignfull has-base-background-color has-background" style="padding-top:var(--wp--preset--spacing--large);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--large);padding-left:var(--wp--preset--spacing--medium)">

<!-- wp:heading {"textAlign":"center","level":3} -->
<h3 class="wp-block-heading has-text-align-center">Find Us On</h3>
<!-- /wp:heading -->

<!-- wp:group {"layout":{"type":"flex","justifyContent":"center","flexWrap":"wrap"}} -->
<div class="wp-block-group">
{$sourcesHtml}
</div>
<!-- /wp:group -->

</div>
<!-- /wp:group -->
BLOCKS;
    }

    protected function buildCtaSection(array $businessInfo): string
    {
        $phone = $businessInfo['phone'] ?? null;
        $phoneButton = $phone
            ? "<!-- wp:button {\"backgroundColor\":\"base\",\"textColor\":\"primary\"} -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link has-primary-color has-base-background-color has-text-color has-background wp-element-button\" href=\"tel:{$phone}\">📞 {$phone}</a></div>\n<!-- /wp:button -->"
            : '';

        return <<<BLOCKS
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large","left":"var:preset|spacing|medium","right":"var:preset|spacing|medium"}}},"backgroundColor":"primary","layout":{"type":"constrained","contentSize":"800px"}} -->
<div class="wp-block-group alignfull has-primary-background-color has-background" style="padding-top:var(--wp--preset--spacing--x-large);padding-right:var(--wp--preset--spacing--medium);padding-bottom:var(--wp--preset--spacing--x-large);padding-left:var(--wp--preset--spacing--medium)">

<!-- wp:heading {"textAlign":"center","textColor":"base"} -->
<h2 class="wp-block-heading has-text-align-center has-base-color has-text-color">Ready to Experience Our Service?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","textColor":"main-accent"} -->
<p class="has-text-align-center has-main-accent-color has-text-color">Join our satisfied customers today. Contact us for a free consultation.</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons">
{$phoneButton}
<!-- wp:button {"backgroundColor":"primary-alt","textColor":"base"} -->
<div class="wp-block-button"><a class="wp-block-button__link has-base-color has-primary-alt-background-color has-text-color has-background wp-element-button" href="/contact">Contact Us</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->

</div>
<!-- /wp:group -->
BLOCKS;
    }

    protected function generateStarRating(float $rating): string
    {
        $fullStars = (int) floor($rating);
        $halfStar = ($rating - $fullStars) >= 0.5;
        $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

        return str_repeat('★', $fullStars)
            .($halfStar ? '½' : '')
            .str_repeat('☆', $emptyStars);
    }
}
