<?php

use App\Jobs\CritiqueRfpProposalJob;
use App\Models\RfpOpportunity;
use App\Models\RfpProposal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches critique for in-play proposals with contacts', function () {
    Queue::fake();

    $opportunity = RfpOpportunity::factory()->create([
        'status' => 'proposal_review',
        'submission_email' => 'rfp@city.gov',
        'submission_deadline' => now()->addWeeks(2),
    ]);
    $proposal = RfpProposal::factory()->for($opportunity, 'opportunity')->create([
        'status' => 'draft',
    ]);

    $this->artisan('rfp:resync-proposals')->assertSuccessful();

    Queue::assertPushed(CritiqueRfpProposalJob::class, fn ($j) => $j->rfpProposalId === $proposal->id);
});

it('skips proposals whose deadline has passed', function () {
    Queue::fake();

    $opportunity = RfpOpportunity::factory()->create([
        'status' => 'proposal_review',
        'submission_email' => 'rfp@city.gov',
        'submission_deadline' => now()->subDay(),
    ]);
    RfpProposal::factory()->for($opportunity, 'opportunity')->create();

    $this->artisan('rfp:resync-proposals')->assertSuccessful();

    Queue::assertNotPushed(CritiqueRfpProposalJob::class);
});

it('skips already submitted proposals', function () {
    Queue::fake();

    $opportunity = RfpOpportunity::factory()->create([
        'status' => 'submitted',
        'submission_email' => 'rfp@city.gov',
        'submission_deadline' => now()->addWeeks(2),
    ]);
    RfpProposal::factory()->for($opportunity, 'opportunity')->create([
        'status' => 'submitted',
    ]);

    $this->artisan('rfp:resync-proposals')->assertSuccessful();

    Queue::assertNotPushed(CritiqueRfpProposalJob::class);
});

it('skips proposals without a contact unless --include-without-contact', function () {
    Queue::fake();

    $opportunity = RfpOpportunity::factory()->create([
        'status' => 'proposal_review',
        'submission_email' => null,
        'organization_website' => null,
        'contact_email' => null,
        'issuing_organization' => 'No Footprint Org '.uniqid(),
        'submission_deadline' => now()->addWeeks(2),
    ]);
    RfpProposal::factory()->for($opportunity, 'opportunity')->create();

    $this->artisan('rfp:resync-proposals')->assertSuccessful();
    Queue::assertNotPushed(CritiqueRfpProposalJob::class);

    $this->artisan('rfp:resync-proposals --include-without-contact')->assertSuccessful();
    Queue::assertPushed(CritiqueRfpProposalJob::class);
});

it('does not dispatch on --dry-run', function () {
    Queue::fake();

    $opportunity = RfpOpportunity::factory()->create([
        'status' => 'proposal_review',
        'submission_email' => 'rfp@city.gov',
        'submission_deadline' => now()->addWeeks(2),
    ]);
    RfpProposal::factory()->for($opportunity, 'opportunity')->create();

    $this->artisan('rfp:resync-proposals --dry-run')->assertSuccessful();

    Queue::assertNotPushed(CritiqueRfpProposalJob::class);
});

it('picks the latest version when multiple proposals exist per opportunity', function () {
    Queue::fake();

    $opportunity = RfpOpportunity::factory()->create([
        'status' => 'proposal_review',
        'submission_email' => 'rfp@city.gov',
        'submission_deadline' => now()->addWeeks(2),
    ]);

    $v1 = RfpProposal::factory()->for($opportunity, 'opportunity')->create(['version' => 1]);
    $v2 = RfpProposal::factory()->for($opportunity, 'opportunity')->create(['version' => 2]);

    $this->artisan('rfp:resync-proposals')->assertSuccessful();

    Queue::assertPushed(CritiqueRfpProposalJob::class, 1);
    Queue::assertPushed(CritiqueRfpProposalJob::class, fn ($j) => $j->rfpProposalId === $v2->id);
});
