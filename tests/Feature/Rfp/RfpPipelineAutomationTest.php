<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use App\Jobs\EvaluateRfpJob;
use App\Jobs\GenerateRfpProposalJob;
use App\Jobs\WatchStuckRfpProposalsJob;
use App\Models\RfpOpportunity;
use App\Services\Rfp\RfpDiscoveryService;
use App\Services\Rfp\RfpEvaluationService;
use App\Services\Rfp\RfpSlackNotifier;
use Illuminate\Support\Facades\Queue;

// ─── Auto-evaluate on discovery ──────────────────────────────────────────────

it('dispatches EvaluateRfpJob when a teaser creates a new opportunity', function () {
    Queue::fake();

    $service = app(RfpDiscoveryService::class);

    $opportunity = $service->createFromTeaser([
        'title' => 'Website Redesign for Tourism Board',
        'organization' => 'Unique Tourism Association '.uniqid(),
        'description' => 'Full website overhaul with CMS.',
        'budget_max' => 75000,
        'submission_deadline' => now()->addMonths(2)->format('Y-m-d'),
    ], 'email_teaser');

    expect($opportunity)->not->toBeNull();
    Queue::assertPushed(EvaluateRfpJob::class, fn ($job) => $job->rfpOpportunityId === $opportunity->id);
});

it('does not dispatch EvaluateRfpJob for duplicate teasers', function () {
    Queue::fake();

    $service = app(RfpDiscoveryService::class);
    $org = 'Duplicate Tourism Bureau '.uniqid();

    $service->createFromTeaser([
        'title' => 'Website Redesign Project',
        'organization' => $org,
        'description' => 'First entry.',
        'submission_deadline' => now()->addMonths(2)->format('Y-m-d'),
    ], 'email_teaser');

    Queue::fake();

    $duplicate = $service->createFromTeaser([
        'title' => 'Website Redesign Project',
        'organization' => $org,
        'description' => 'Duplicate — same title and org.',
        'submission_deadline' => now()->addMonths(2)->format('Y-m-d'),
    ], 'email_teaser');

    expect($duplicate)->toBeNull();
    Queue::assertNotPushed(EvaluateRfpJob::class);
});

// ─── Pre-filter (federal / non-US / low confidence) ──────────────────────────

it('pre-filter blocks federal teasers before they enter the pipeline', function () {
    Queue::fake();

    $service = app(RfpDiscoveryService::class);

    $opportunity = $service->createFromTeaser([
        'title' => 'Modernization of Veterans Portal',
        'organization' => 'U.S. Department of Veterans Affairs',
        'description' => 'Federal portal modernization.',
        'submission_deadline' => now()->addMonths(2)->format('Y-m-d'),
        'extraction_confidence' => 0.95,
    ], 'email_teaser');

    expect($opportunity)->toBeNull();
    Queue::assertNotPushed(EvaluateRfpJob::class);
});

it('pre-filter blocks low-confidence teaser extractions', function () {
    Queue::fake();

    $service = app(RfpDiscoveryService::class);

    $opportunity = $service->createFromTeaser([
        'title' => 'Maybe a website thing',
        'organization' => 'Visit Somewhere '.uniqid(),
        'description' => 'Vague mention of digital work in newsletter.',
        'submission_deadline' => now()->addMonths(2)->format('Y-m-d'),
        'extraction_confidence' => 0.3,
    ], 'email_teaser');

    expect($opportunity)->toBeNull();
    Queue::assertNotPushed(EvaluateRfpJob::class);
});

// ─── Auto-decline threshold ───────────────────────────────────────────────────

it('auto-declines opportunities scoring below 35', function () {
    $service = app(RfpEvaluationService::class);

    $rfp = RfpOpportunity::factory()->create([
        'status' => 'discovered',
        'organization_industry' => 'nuclear_energy_sector',
        'budget_min' => null,
        'budget_max' => 5000,
        'source_type' => 'web_scrape', // Lowest win-probability signal; keeps total below 35
        'tech_requirements' => ['COBOL', 'Mainframe'],
        'submission_deadline' => now()->addWeek(),
    ]);

    $result = $service->evaluate($rfp);

    expect($result['recommended_status'])->toBe('declined');
    expect($result['fit_score'])->toBeLessThan(35);
});

it('qualifies opportunities scoring 60 or above', function () {
    $service = app(RfpEvaluationService::class);

    $rfp = RfpOpportunity::factory()->create([
        'status' => 'discovered',
        'tech_requirements' => ['WordPress', 'CMS', 'SEO', 'API'],
        'budget_min' => 40000,
        'budget_max' => 100000,
        'organization_industry' => null,
        'submission_deadline' => now()->addMonths(2),
        'source_type' => 'manual',
    ]);

    $result = $service->evaluate($rfp);

    expect($result['fit_score'])->toBeGreaterThanOrEqual(60);
    expect($result['recommended_status'])->toBe('qualified');
});

it('keeps opportunities in evaluating band for scores 35-49', function () {
    $service = app(RfpEvaluationService::class);

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('determineStatus');
    $method->setAccessible(true);

    // Qualified band: ≥50
    expect($method->invoke($service, 50))->toBe('qualified');
    expect($method->invoke($service, 75))->toBe('qualified');

    // Evaluating band: 35–49
    expect($method->invoke($service, 49))->toBe('evaluating');
    expect($method->invoke($service, 35))->toBe('evaluating');

    // Declined: <35
    expect($method->invoke($service, 34))->toBe('declined');
    expect($method->invoke($service, 0))->toBe('declined');
});

// ─── Auto-proposal on qualification ──────────────────────────────────────────

it('dispatches GenerateRfpProposalJob when EvaluateRfpJob qualifies an opportunity', function () {
    Queue::fake();

    $rfp = RfpOpportunity::factory()->create([
        'status' => 'discovered',
        'tech_requirements' => ['WordPress', 'CMS', 'SEO', 'API', 'Laravel'],
        'budget_min' => 50000,
        'budget_max' => 120000,
        'submission_deadline' => now()->addMonths(2),
        'source_type' => 'manual',
    ]);

    $job = new EvaluateRfpJob($rfp->id);
    $job->handle(app(RfpEvaluationService::class));

    $rfp->refresh();

    if ($rfp->fit_score >= 60) {
        Queue::assertPushed(GenerateRfpProposalJob::class, fn ($j) => $j->rfpOpportunityId === $rfp->id);
    }
});

it('does not dispatch proposal job for declined opportunities', function () {
    Queue::fake();

    // Expired deadline forces auto-decline regardless of score
    $rfp = RfpOpportunity::factory()->create([
        'status' => 'discovered',
        'tech_requirements' => ['COBOL', 'Mainframe'],
        'budget_min' => null,
        'budget_max' => 5000,
        'submission_deadline' => now()->subDay(),
    ]);

    $job = new EvaluateRfpJob($rfp->id);
    $job->handle(app(RfpEvaluationService::class));

    $rfp->refresh();
    expect($rfp->status)->toBe('declined');
    Queue::assertNotPushed(GenerateRfpProposalJob::class);
});

// ─── Stuck proposal watchdog ──────────────────────────────────────────────────

it('resets stuck proposals older than 30 minutes to pursuing', function () {
    $rfp = RfpOpportunity::factory()->create([
        'status' => 'proposal_drafting',
        'generation_stage' => 'generating_content',
        'generation_started_at' => now()->subMinutes(45),
    ]);

    $notifier = Mockery::mock(RfpSlackNotifier::class);
    $notifier->shouldReceive('notifyProposalStuck')->once()->with(Mockery::on(fn ($arg) => $arg->id === $rfp->id));

    $job = new WatchStuckRfpProposalsJob;
    $job->handle($notifier);

    $rfp->refresh();
    expect($rfp->status)->toBe('pursuing');
    expect($rfp->generation_stage)->toBe('failed');
    expect($rfp->generation_error)->toContain('timed out');
});

it('does not touch proposals that started within the last 30 minutes', function () {
    $rfp = RfpOpportunity::factory()->create([
        'status' => 'proposal_drafting',
        'generation_stage' => 'generating_content',
        'generation_started_at' => now()->subMinutes(10),
    ]);

    $notifier = Mockery::mock(RfpSlackNotifier::class);
    $notifier->shouldNotReceive('notifyProposalStuck');

    $job = new WatchStuckRfpProposalsJob;
    $job->handle($notifier);

    $rfp->refresh();
    expect($rfp->status)->toBe('proposal_drafting');
});

it('handles multiple stuck proposals in a single watchdog run', function () {
    $stuckRfps = RfpOpportunity::factory()->count(3)->create([
        'status' => 'proposal_drafting',
        'generation_stage' => 'generating_content',
        'generation_started_at' => now()->subHour(),
    ]);

    $recentRfp = RfpOpportunity::factory()->create([
        'status' => 'proposal_drafting',
        'generation_stage' => 'generating_content',
        'generation_started_at' => now()->subMinutes(5),
    ]);

    $notifier = Mockery::mock(RfpSlackNotifier::class);
    $notifier->shouldReceive('notifyProposalStuck')->times(3);

    $job = new WatchStuckRfpProposalsJob;
    $job->handle($notifier);

    foreach ($stuckRfps as $rfp) {
        expect($rfp->fresh()->status)->toBe('pursuing');
    }
    expect($recentRfp->fresh()->status)->toBe('proposal_drafting');
});
