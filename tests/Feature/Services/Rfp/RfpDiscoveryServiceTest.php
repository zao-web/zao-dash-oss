<?php

use App\Models\RfpOpportunity;
use App\Services\AI\ClaudeCliService;
use App\Services\Rfp\RfpDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('parseEmailTeasers extracts opportunities from email body', function () {
    $claude = $this->mock(ClaudeCliService::class);
    $claude->shouldReceive('messageJson')
        ->once()
        ->andReturn([
            [
                'title' => 'Explore St. Louis Website Redesign',
                'organization' => 'Explore St. Louis',
                'description' => 'Full destination platform with CMS and trip planning tools',
                'budget_min' => 200000,
                'budget_max' => 350000,
                'tech_keywords' => ['CMS', 'SEO', 'AI'],
            ],
            [
                'title' => 'Dispute Resolution Center WordPress Rebuild',
                'organization' => 'Dispute Resolution Center of King County',
                'description' => 'WordPress rebuild with WCAG 2.1 AA compliance',
                'budget_min' => 35000,
                'budget_max' => 65000,
                'tech_keywords' => ['WordPress', 'CRM', 'accessibility'],
            ],
        ]);

    $service = app(RfpDiscoveryService::class);

    $results = $service->parseEmailTeasers('New projects seeking agencies: Explore St. Louis needs a full destination platform...');

    expect($results)->toHaveCount(2)
        ->and($results[0]['organization'])->toBe('Explore St. Louis')
        ->and($results[1]['organization'])->toBe('Dispute Resolution Center of King County');
});

test('isDuplicate detects existing opportunities', function () {
    RfpOpportunity::factory()->create([
        'title' => 'Portland Website Redesign',
        'issuing_organization' => 'City of Portland',
    ]);

    $service = app(RfpDiscoveryService::class);

    expect($service->isDuplicate('Portland Website Redesign', 'City of Portland'))->toBeTrue()
        ->and($service->isDuplicate('Something Else', 'Other Org'))->toBeFalse();
});

test('createFromTeaser creates an rfp opportunity', function () {
    $service = app(RfpDiscoveryService::class);

    $rfp = $service->createFromTeaser([
        'title' => 'Explore St. Louis Website Redesign',
        'organization' => 'Explore St. Louis',
        'description' => 'Full destination platform',
        'budget_min' => 200000,
        'budget_max' => 350000,
        'tech_keywords' => ['CMS', 'SEO'],
    ], 'email_teaser');

    expect($rfp)->toBeInstanceOf(RfpOpportunity::class)
        ->and($rfp->title)->toBe('Explore St. Louis Website Redesign')
        ->and($rfp->issuing_organization)->toBe('Explore St. Louis')
        ->and($rfp->source_type)->toBe('email_teaser')
        ->and($rfp->status)->toBe('discovered')
        ->and($rfp->budget_min)->toBe('200000.00')
        ->and($rfp->tech_requirements)->toBe(['CMS', 'SEO']);
});

test('createFromTeaser skips duplicates', function () {
    RfpOpportunity::factory()->create([
        'title' => 'Explore St. Louis Website Redesign',
        'issuing_organization' => 'Explore St. Louis',
    ]);

    $service = app(RfpDiscoveryService::class);

    $rfp = $service->createFromTeaser([
        'title' => 'Explore St. Louis Website Redesign',
        'organization' => 'Explore St. Louis',
        'description' => 'Duplicate',
    ], 'email_teaser');

    expect($rfp)->toBeNull();

    $this->assertDatabaseCount('rfp_opportunities', 1);
});

test('createFromTeaser skips opportunities with past deadlines', function () {
    $service = app(RfpDiscoveryService::class);

    $rfp = $service->createFromTeaser([
        'title' => 'Cowlitz County Tourism Site',
        'organization' => 'Cowlitz County',
        'description' => 'Destination marketing website',
        'submission_deadline' => now()->subWeeks(3)->format('Y-m-d'),
    ], 'email_teaser');

    expect($rfp)->toBeNull();
    $this->assertDatabaseCount('rfp_opportunities', 0);
});

test('createFromTeaser allows opportunities with future deadlines', function () {
    $service = app(RfpDiscoveryService::class);

    $rfp = $service->createFromTeaser([
        'title' => 'Portland Website Redesign',
        'organization' => 'City of Portland',
        'description' => 'Full redesign',
        'submission_deadline' => now()->addMonth()->format('Y-m-d'),
    ], 'email_teaser');

    expect($rfp)->toBeInstanceOf(RfpOpportunity::class);
    $this->assertDatabaseCount('rfp_opportunities', 1);
});

test('createFromTeaser allows opportunities with no deadline', function () {
    $service = app(RfpDiscoveryService::class);

    $rfp = $service->createFromTeaser([
        'title' => 'Open-Ended Project',
        'organization' => 'Some Org',
        'description' => 'No deadline specified',
    ], 'manual');

    expect($rfp)->toBeInstanceOf(RfpOpportunity::class);
});

test('parseEmailTeasers returns empty array for short body', function () {
    $service = app(RfpDiscoveryService::class);

    $results = $service->parseEmailTeasers('Hi');

    expect($results)->toBeEmpty();
});
