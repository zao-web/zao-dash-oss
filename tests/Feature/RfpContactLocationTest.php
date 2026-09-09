<?php

use App\Jobs\GenerateRfpProposalJob;
use App\Jobs\LocateRfpContactJob;
use App\Models\Email;
use App\Models\RfpOpportunity;
use App\Services\Rfp\RfpContactLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('promotes contact_email to submission_email when only contact_email is set', function () {
    $opportunity = RfpOpportunity::factory()->create([
        'submission_email' => null,
        'contact_email' => 'purchasing@example.gov',
        'contact_name' => 'Procurement',
    ]);

    $result = app(RfpContactLocator::class)->locate($opportunity);

    expect($result['found'])->toBeTrue()
        ->and($result['email'])->toBe('purchasing@example.gov')
        ->and($result['source'])->toBe('contact_email_field');
});

it('returns the existing submission_email when one is already set', function () {
    $opportunity = RfpOpportunity::factory()->create([
        'submission_email' => 'rfp@portland.gov',
        'contact_name' => 'Procurement Office',
    ]);

    $result = app(RfpContactLocator::class)->locate($opportunity);

    expect($result['found'])->toBeTrue()
        ->and($result['email'])->toBe('rfp@portland.gov')
        ->and($result['source'])->toBe('opportunity_field');
});

it('locates a contact from past gmail history matching the org domain', function () {
    $opportunity = RfpOpportunity::factory()->create([
        'submission_email' => null,
        'organization_website' => 'https://traveloregon.com',
        'issuing_organization' => 'Travel Oregon',
    ]);

    Email::factory()->create([
        'from_address' => 'sara@traveloregon.com',
        'from_name' => 'Sara Procurement',
        'received_at' => now()->subDays(20),
    ]);

    $result = app(RfpContactLocator::class)->locate($opportunity);

    expect($result['found'])->toBeTrue()
        ->and($result['email'])->toBe('sara@traveloregon.com')
        ->and($result['source'])->toBe('gmail_history');
});

it('returns not found when no signals are available', function () {
    $opportunity = RfpOpportunity::factory()->create([
        'submission_email' => null,
        'organization_website' => null,
        'contact_email' => null,
        'issuing_organization' => 'Mystery Org '.uniqid(),
    ]);

    $result = app(RfpContactLocator::class)->locate($opportunity);

    expect($result['found'])->toBeFalse()
        ->and($result['email'])->toBeNull();
});

it('halts proposal generation and dispatches LocateRfpContactJob when no contact', function () {
    Queue::fake();

    $opportunity = RfpOpportunity::factory()->create([
        'submission_email' => null,
        'status' => 'pursuing',
    ]);

    // Inject a stub proposal service — we should never reach it.
    $stub = Mockery::mock(\App\Services\Rfp\RfpProposalService::class);
    $stub->shouldNotReceive('generateProposal');

    (new GenerateRfpProposalJob($opportunity->id))->handle($stub);

    Queue::assertPushed(LocateRfpContactJob::class, fn ($job) => $job->rfpOpportunityId === $opportunity->id);

    expect($opportunity->fresh()->status)->toBe('pursuing');
});

it('marks opportunity contact_needed when locator finds nothing', function () {
    $opportunity = RfpOpportunity::factory()->create([
        'submission_email' => null,
        'organization_website' => null,
        'contact_email' => null,
        'issuing_organization' => 'No Footprint Org '.uniqid(),
        'status' => 'pursuing',
    ]);

    (new LocateRfpContactJob($opportunity->id))->handle(
        app(RfpContactLocator::class),
        app(\App\Services\Rfp\RfpSlackNotifier::class),
    );

    expect($opportunity->fresh()->status)->toBe('contact_needed');
});

it('continues to proposal generation when locator finds a contact', function () {
    Queue::fake();

    $opportunity = RfpOpportunity::factory()->create([
        'submission_email' => null,
        'organization_website' => 'https://visitcalifornia.com',
        'issuing_organization' => 'Visit California',
    ]);

    Email::factory()->create([
        'from_address' => 'marketing@visitcalifornia.com',
        'from_name' => 'Visit CA Marketing',
        'received_at' => now()->subDays(5),
    ]);

    (new LocateRfpContactJob($opportunity->id))->handle(
        app(RfpContactLocator::class),
        app(\App\Services\Rfp\RfpSlackNotifier::class),
    );

    expect($opportunity->fresh()->submission_email)->toBe('marketing@visitcalifornia.com');
    Queue::assertPushed(GenerateRfpProposalJob::class, fn ($job) => $job->rfpOpportunityId === $opportunity->id);
});
