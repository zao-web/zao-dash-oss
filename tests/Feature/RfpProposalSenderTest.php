<?php

use App\Mail\RfpProposalMail;
use App\Models\RfpOpportunity;
use App\Models\RfpProposal;
use App\Services\Rfp\RfpProposalSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('mails the proposal and updates statuses', function () {
    Mail::fake();

    $opportunity = RfpOpportunity::factory()->create([
        'submission_email' => 'rfp@portland.gov',
        'contact_name' => 'Procurement Office',
        'status' => 'proposal_review',
    ]);
    $proposal = RfpProposal::factory()->for($opportunity, 'opportunity')->create([
        'status' => 'draft',
    ]);

    app(RfpProposalSender::class)->send($proposal, $opportunity);

    Mail::assertSent(RfpProposalMail::class, function ($mail) {
        return $mail->hasTo('rfp@portland.gov');
    });

    expect($proposal->fresh()->status)->toBe('submitted')
        ->and($proposal->fresh()->submitted_via)->toBe('email')
        ->and($opportunity->fresh()->status)->toBe('submitted');
});

it('throws when opportunity has no submission_email', function () {
    $opportunity = RfpOpportunity::factory()->create(['submission_email' => null]);
    $proposal = RfpProposal::factory()->for($opportunity, 'opportunity')->create();

    expect(fn () => app(RfpProposalSender::class)->send($proposal, $opportunity))
        ->toThrow(RuntimeException::class);
});

it('preserves a non-default opportunity status like won', function () {
    Mail::fake();

    $opportunity = RfpOpportunity::factory()->create([
        'submission_email' => 'rfp@example.com',
        'status' => 'won',
    ]);
    $proposal = RfpProposal::factory()->for($opportunity, 'opportunity')->create();

    app(RfpProposalSender::class)->send($proposal, $opportunity);

    expect($opportunity->fresh()->status)->toBe('won');
});
