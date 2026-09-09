<?php

use App\Agents\Tools\WebsiteBuilder\WebsiteBuilderSocialProofAggregatorTool;
use App\Models\WebsiteProject;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->user = \App\Models\User::factory()->create();
    $this->actingAs($this->user);
});

describe('WebsiteBuilderSocialProofAggregatorTool', function () {
    it('has correct metadata', function () {
        $tool = new WebsiteBuilderSocialProofAggregatorTool;

        expect($tool->name())->toBe('Social Proof Aggregator')
            ->and($tool->category())->toBe('website-builder')
            ->and($tool->id())->toBe('website-builder-social-proof-aggregator');
    });

    it('has correct input schema', function () {
        $tool = new WebsiteBuilderSocialProofAggregatorTool;

        $schema = $tool->inputSchema();

        expect($schema['required'])->toContain('business_name')
            ->and($schema['properties'])->toHaveKeys([
                'project_id',
                'business_name',
                'location',
                'website_url',
                'google_place_id',
                'yelp_business_id',
                'sources',
                'min_rating',
            ]);
    });

    it('validates required parameters', function () {
        $tool = new WebsiteBuilderSocialProofAggregatorTool;

        $schema = $tool->inputSchema();
        expect($schema['required'])->toContain('business_name');
    });

    it('filters reviews by minimum rating', function () {
        $tool = new WebsiteBuilderSocialProofAggregatorTool;

        $method = new \ReflectionMethod($tool, 'filterAndRankReviews');
        $method->setAccessible(true);

        $reviews = [
            ['text' => 'Great!', 'rating' => 5, 'source' => 'google'],
            ['text' => 'Good', 'rating' => 4, 'source' => 'google'],
            ['text' => 'Okay', 'rating' => 3, 'source' => 'google'],
            ['text' => 'Bad', 'rating' => 2, 'source' => 'google'],
        ];

        $filtered = $method->invoke($tool, $reviews, 4);

        expect(count($filtered))->toBe(2)
            ->and($filtered[0]['rating'])->toBeGreaterThanOrEqual(4);
    });

    it('calculates average rating correctly', function () {
        $tool = new WebsiteBuilderSocialProofAggregatorTool;

        $method = new \ReflectionMethod($tool, 'calculateAverageRating');
        $method->setAccessible(true);

        $reviews = [
            ['rating' => 5],
            ['rating' => 4],
            ['rating' => 5],
            ['rating' => 3],
        ];

        $average = $method->invoke($tool, $reviews);

        expect($average)->toBe(4.3);
    });

    it('extracts short quotes from long text', function () {
        $tool = new WebsiteBuilderSocialProofAggregatorTool;

        $method = new \ReflectionMethod($tool, 'extractShortQuote');
        $method->setAccessible(true);

        $longText = 'This is a really long review that goes on and on and on. It contains a lot of details about the service. The reviewer really liked everything about their experience. They would definitely recommend this business to everyone they know.';

        $shortQuote = $method->invoke($tool, $longText, 100);

        expect(strlen($shortQuote))->toBeLessThanOrEqual(103);
    });

    it('ranks reviews by quality score', function () {
        $tool = new WebsiteBuilderSocialProofAggregatorTool;

        $method = new \ReflectionMethod($tool, 'calculateReviewScore');
        $method->setAccessible(true);

        $highQualityReview = [
            'text' => 'This is a detailed review with enough content to be useful.',
            'rating' => 5,
            'author' => 'John Smith',
            'profile_photo' => 'https://example.com/photo.jpg',
            'source' => 'google',
        ];

        $lowQualityReview = [
            'text' => 'Good',
            'rating' => 4,
            'author' => 'Anonymous',
            'source' => 'website',
        ];

        $highScore = $method->invoke($tool, $highQualityReview);
        $lowScore = $method->invoke($tool, $lowQualityReview);

        expect($highScore)->toBeGreaterThan($lowScore);
    });

    it('selects diverse testimonials from multiple sources', function () {
        $tool = new WebsiteBuilderSocialProofAggregatorTool;

        $method = new \ReflectionMethod($tool, 'selectTopTestimonials');
        $method->setAccessible(true);

        $reviews = [
            ['text' => 'Google review 1', 'rating' => 5, 'source' => 'google', 'author' => 'User 1', 'score' => 100],
            ['text' => 'Google review 2', 'rating' => 5, 'source' => 'google', 'author' => 'User 2', 'score' => 99],
            ['text' => 'Yelp review 1', 'rating' => 5, 'source' => 'yelp', 'author' => 'User 3', 'score' => 98],
            ['text' => 'Facebook review 1', 'rating' => 5, 'source' => 'facebook', 'author' => 'User 4', 'score' => 97],
            ['text' => 'Google review 3', 'rating' => 5, 'source' => 'google', 'author' => 'User 5', 'score' => 96],
        ];

        $selected = $method->invoke($tool, $reviews, 3);

        $sources = array_column($selected, 'source');

        expect(count(array_unique($sources)))->toBeGreaterThan(1);
    });

    it('handles website testimonial scraping', function () {
        Http::fake([
            'https://example.com' => Http::response('<html>
                <div class="testimonial">Great service! - John</div>
                <blockquote class="testimonial">Amazing work done by this company.</blockquote>
            </html>', 200),
        ]);

        $tool = new WebsiteBuilderSocialProofAggregatorTool;

        $method = new \ReflectionMethod($tool, 'scrapeWebsiteTestimonials');
        $method->setAccessible(true);

        $result = $method->invoke($tool, 'https://example.com');

        expect($result['success'])->toBeTrue()
            ->and($result['reviews'])->toBeArray();
    });

    it('stores social proof data in project when project_id provided', function () {
        Http::fake([
            '*' => Http::response(['error' => 'Not configured'], 401),
        ]);

        $project = WebsiteProject::factory()->create([
            'user_id' => $this->user->id,
            'source_data' => [],
        ]);

        $tool = new WebsiteBuilderSocialProofAggregatorTool;
        $result = $tool->execute([
            'project_id' => $project->id,
            'business_name' => 'Test Business',
            'location' => 'Portland, OR',
            'sources' => [],
        ]);

        expect($result['stored_in_project'])->toBeTrue();

        $project->refresh();
        expect($project->source_data)->toHaveKey('social_proof')
            ->and($project->source_data['social_proof']['business_name'])->toBe('Test Business');
    });
});
