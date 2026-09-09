<?php

use App\Jobs\EvaluateRfpJob;
use App\Models\RfpOpportunity;
use App\Services\Rfp\RfpEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('evaluation service recommends declined for expired opportunities', function () {
    $opportunity = RfpOpportunity::factory()->create([
        'submission_deadline' => now()->subWeek(),
        'tech_requirements' => ['WordPress', 'CMS', 'SEO'],
        'organization_industry' => 'tourism',
        'budget_min' => 50000,
        'budget_max' => 100000,
    ]);

    $service = app(RfpEvaluationService::class);
    $result = $service->evaluate($opportunity);

    expect($result['recommended_status'])->toBe('declined')
        ->and($result['recommended_priority'])->toBe('low')
        ->and($result['fit_score_breakdown']['timeline']['score'])->toBe(0);
});

test('evaluation service qualifies opportunities with future deadlines', function () {
    $opportunity = RfpOpportunity::factory()->create([
        'submission_deadline' => now()->addMonths(2),
        'tech_requirements' => ['WordPress', 'CMS', 'SEO'],
        'organization_industry' => 'tourism',
        'budget_min' => 50000,
        'budget_max' => 100000,
    ]);

    $service = app(RfpEvaluationService::class);
    $result = $service->evaluate($opportunity);

    expect($result['recommended_status'])->not->toBe('declined');
});

test('evaluate rfp job auto-declines expired opportunities without scoring', function () {
    $opportunity = RfpOpportunity::factory()->create([
        'status' => 'discovered',
        'submission_deadline' => now()->subWeeks(3),
    ]);

    (new EvaluateRfpJob($opportunity->id))->handle(app(RfpEvaluationService::class));

    $opportunity->refresh();

    expect($opportunity->status)->toBe('declined')
        ->and($opportunity->priority)->toBe('low')
        ->and($opportunity->decline_reason)->toContain('Deadline passed');
});

test('evaluate rfp job processes non-expired opportunities normally', function () {
    $opportunity = RfpOpportunity::factory()->create([
        'status' => 'discovered',
        'submission_deadline' => now()->addMonths(2),
        'tech_requirements' => ['WordPress', 'CMS'],
        'budget_min' => 50000,
        'budget_max' => 100000,
    ]);

    (new EvaluateRfpJob($opportunity->id))->handle(app(RfpEvaluationService::class));

    $opportunity->refresh();

    expect($opportunity->fit_score)->toBeGreaterThan(0)
        ->and($opportunity->fit_score_breakdown)->not->toBeNull();
});

test('batch evaluation declines expired and processes active', function () {
    $expired = RfpOpportunity::factory()->create([
        'status' => 'discovered',
        'submission_deadline' => now()->subDay(),
    ]);
    $active = RfpOpportunity::factory()->create([
        'status' => 'discovered',
        'submission_deadline' => now()->addMonth(),
        'tech_requirements' => ['WordPress'],
        'budget_min' => 50000,
        'budget_max' => 100000,
    ]);

    (new EvaluateRfpJob)->handle(app(RfpEvaluationService::class));

    expect($expired->fresh()->status)->toBe('declined')
        ->and($active->fresh()->status)->not->toBe('declined');
});
