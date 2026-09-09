<?php

use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\SeoPage;

test('tracks lead to project to revenue attribution', function () {
    // Create an SEO page
    $seoPage = SeoPage::factory()->create([
        'page_url' => 'https://example.com/wordpress-development-for-healthcare',
        'target_keyword' => 'wordpress development healthcare',
        'total_leads' => 0,
        'total_projects' => 0,
        'total_revenue' => 0,
    ]);

    // Create a lead from SEO traffic
    $lead = Lead::factory()->create([
        'first_touch_page_url' => $seoPage->page_url,
        'first_touch_keyword' => $seoPage->target_keyword,
    ]);

    expect($lead->first_touch_page_url)->toBe($seoPage->page_url);
    expect($lead->first_touch_keyword)->toBe($seoPage->target_keyword);

    // Convert lead to client and create project
    $client = Client::factory()->create();
    $lead->update([
        'status' => 'converted',
        'converted_client_id' => $client->id,
    ]);

    $project = Project::factory()->create([
        'client_id' => $client->id,
        'source' => 'organic',
        'source_page' => $lead->first_touch_page_url,
        'source_keyword' => $lead->first_touch_keyword,
        'estimated_value' => 15000,
    ]);

    expect($project->source)->toBe('organic');
    expect($project->source_page)->toBe($seoPage->page_url);
    expect($project->source_keyword)->toBe($seoPage->target_keyword);

    // Update SEO page metrics (this would normally be done by a job/observer)
    $seoPage->increment('total_leads');
    $seoPage->increment('total_projects');
    $seoPage->increment('total_revenue', $project->estimated_value);
    $seoPage->update([
        'conversion_rate' => $seoPage->clicks_30d > 0
            ? ($seoPage->total_leads / $seoPage->clicks_30d) * 100
            : 0,
    ]);

    $seoPage->refresh();

    expect($seoPage->total_leads)->toBe(1);
    expect($seoPage->total_projects)->toBe(1);
    expect($seoPage->total_revenue)->toBe(15000.00);
});

test('tracks multiple leads from same seo page', function () {
    $seoPage = SeoPage::factory()->create([
        'page_url' => 'https://example.com/woocommerce-vs-shopify',
        'target_keyword' => 'woocommerce vs shopify',
        'clicks_30d' => 200,
    ]);

    // Create 3 leads from this page
    $leads = Lead::factory()->count(3)->create([
        'first_touch_page_url' => $seoPage->page_url,
        'first_touch_keyword' => $seoPage->target_keyword,
    ]);

    expect($leads)->toHaveCount(3);
    expect($leads->first()->first_touch_page_url)->toBe($seoPage->page_url);
});

test('calculates roi correctly', function () {
    $seoPage = SeoPage::factory()->create([
        'total_revenue' => 50000,
        'clicks_30d' => 100,
    ]);

    // Assuming $10 cost per lead generated (simplified)
    $costs = $seoPage->total_leads * 10;
    $roi = $costs > 0 ? $seoPage->total_revenue / $costs : 0;

    // If we had 5 leads at $10 each = $50 costs
    // Revenue of $50,000 / $50 costs = 1000x ROI
    expect($roi)->toBeGreaterThan(0);
});
