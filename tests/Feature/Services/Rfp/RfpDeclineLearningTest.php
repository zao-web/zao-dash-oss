<?php

use App\Models\RfpLearningInsight;
use App\Models\RfpOpportunity;
use App\Models\User;
use App\Services\Rfp\RfpDiscoveryService;
use App\Services\Rfp\RfpEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($this->user);
});

test('declining an RFP requires a category', function () {
    $rfp = RfpOpportunity::factory()->create();

    $response = $this->delete("/rfp/{$rfp->id}", []);

    $response->assertSessionHasErrors('decline_category');
});

test('declining an RFP stores reason and creates learning insight', function () {
    $rfp = RfpOpportunity::factory()->create([
        'title' => 'Northwestern Michigan College Site Redesign',
        'issuing_organization' => 'Northwestern Michigan College',
        'organization_industry' => 'education',
    ]);

    $response = $this->delete("/rfp/{$rfp->id}", [
        'decline_category' => 'wrong_industry',
        'decline_notes' => 'Higher education not our niche',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    // RFP should be soft deleted with reason
    $this->assertSoftDeleted('rfp_opportunities', ['id' => $rfp->id]);

    $deletedRfp = RfpOpportunity::withTrashed()->find($rfp->id);
    expect($deletedRfp->status)->toBe('declined')
        ->and($deletedRfp->decline_reason)->toContain('Wrong industry')
        ->and($deletedRfp->decline_reason)->toContain('Higher education not our niche');

    // Learning insight should be created
    $insight = RfpLearningInsight::where('insight_type', 'decline_pattern')->first();
    expect($insight)->not->toBeNull()
        ->and($insight->evidence['category'])->toBe('wrong_industry')
        ->and($insight->evidence['organization'])->toBe('Northwestern Michigan College')
        ->and($insight->evidence['organization_industry'])->toBe('education')
        ->and($insight->impact_area)->toBe('targeting')
        ->and($insight->is_active)->toBeTrue();
});

test('evaluation penalizes opportunities matching declined org', function () {
    // Create a decline pattern for Northwestern Michigan College
    RfpLearningInsight::create([
        'insight_type' => 'decline_pattern',
        'title' => 'Declined: Wrong industry',
        'description' => 'Declined Northwestern Michigan College',
        'evidence' => [
            'category' => 'wrong_industry',
            'organization' => 'Northwestern Michigan College',
            'organization_industry' => 'education',
        ],
        'confidence' => 1.00,
        'impact_area' => 'targeting',
        'is_active' => true,
    ]);

    $opportunity = RfpOpportunity::factory()->create([
        'issuing_organization' => 'Northwestern Michigan College',
        'submission_deadline' => now()->addMonth(),
        'tech_requirements' => ['WordPress', 'CMS'],
        'budget_min' => 50000,
        'budget_max' => 100000,
    ]);

    $service = app(RfpEvaluationService::class);
    $result = $service->evaluate($opportunity);

    // Should have a negative decline_history score
    expect($result['fit_score_breakdown']['decline_history']['score'])->toBeLessThan(0)
        ->and($result['fit_score_breakdown']['decline_history']['details'])->toContain('Previously declined');
});

test('evaluation penalizes same industry decline patterns', function () {
    // Decline multiple education RFPs
    RfpLearningInsight::create([
        'insight_type' => 'decline_pattern',
        'title' => 'Declined: Wrong industry',
        'description' => 'Declined College of the Ozarks',
        'evidence' => [
            'category' => 'wrong_industry',
            'organization' => 'College of the Ozarks',
            'organization_industry' => 'education',
        ],
        'confidence' => 1.00,
        'impact_area' => 'targeting',
        'is_active' => true,
    ]);

    $opportunity = RfpOpportunity::factory()->create([
        'issuing_organization' => 'Boise State University',
        'organization_industry' => 'education',
        'submission_deadline' => now()->addMonth(),
        'tech_requirements' => ['WordPress'],
        'budget_min' => 50000,
        'budget_max' => 100000,
    ]);

    $service = app(RfpEvaluationService::class);
    $result = $service->evaluate($opportunity);

    expect($result['fit_score_breakdown']['decline_history']['score'])->toBeLessThan(0)
        ->and($result['fit_score_breakdown']['decline_history']['details'])->toContain('education');
});

test('evaluation gives no penalty when no decline patterns exist', function () {
    $opportunity = RfpOpportunity::factory()->create([
        'submission_deadline' => now()->addMonth(),
        'tech_requirements' => ['WordPress'],
        'budget_min' => 50000,
        'budget_max' => 100000,
    ]);

    $service = app(RfpEvaluationService::class);
    $result = $service->evaluate($opportunity);

    expect($result['fit_score_breakdown']['decline_history']['score'])->toBe(0);
});

test('discovery flags new opportunities matching decline patterns', function () {
    RfpLearningInsight::create([
        'insight_type' => 'decline_pattern',
        'title' => 'Declined: Wrong industry',
        'description' => 'Declined Northwestern Michigan College',
        'evidence' => [
            'category' => 'wrong_industry',
            'organization' => 'Northwestern Michigan College',
        ],
        'confidence' => 1.00,
        'impact_area' => 'targeting',
        'is_active' => true,
    ]);

    $service = app(RfpDiscoveryService::class);
    $rfp = $service->createFromTeaser([
        'title' => 'NMC New Student Portal',
        'organization' => 'Northwestern Michigan College',
        'description' => 'Student portal redesign',
        'submission_deadline' => now()->addMonth()->format('Y-m-d'),
    ], 'email_teaser');

    // Should still be created but with lowered priority
    expect($rfp)->not->toBeNull()
        ->and($rfp->priority)->toBe('low');
});
