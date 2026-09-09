<?php

use App\Jobs\CritiqueRfpProposalJob;
use App\Models\RfpOpportunity;
use App\Models\RfpProposal;
use App\Services\Rfp\RfpProposalCritic;
use App\Services\Rfp\RfpSlackNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('saves critique findings + summary on the proposal', function () {
    $opportunity = RfpOpportunity::factory()->create();
    $proposal = RfpProposal::factory()->for($opportunity, 'opportunity')->create();

    $critic = Mockery::mock(RfpProposalCritic::class);
    $critic->shouldReceive('critique')->once()->andReturn([
        'findings' => [
            ['severity' => 'warning', 'category' => 'tone', 'detail' => 'A bit sales-y in section 3.'],
        ],
        'blocker_count' => 0,
        'warning_count' => 1,
        'summary' => 'Solid draft with one tone nit.',
    ]);
    $critic->shouldNotReceive('revise');

    $slack = Mockery::mock(RfpSlackNotifier::class);
    $slack->shouldReceive('notifyProposalReady')->once();

    (new CritiqueRfpProposalJob($proposal->id))->handle($critic, $slack);

    $fresh = $proposal->fresh();
    expect($fresh->critique_blocker_count)->toBe(0)
        ->and($fresh->critique_revised)->toBeFalse()
        ->and($fresh->critique_summary)->toBe('Solid draft with one tone nit.')
        ->and($fresh->critique_findings)->toHaveCount(1);
});

it('runs a revision pass when critique reports blockers', function () {
    $opportunity = RfpOpportunity::factory()->create();
    $proposal = RfpProposal::factory()->for($opportunity, 'opportunity')->create();

    $critique = [
        'findings' => [
            ['severity' => 'blocker', 'category' => 'duplicate', 'detail' => 'Executive Summary appears twice.'],
        ],
        'blocker_count' => 1,
        'warning_count' => 0,
        'summary' => 'Duplicate Exec Summary — revised.',
    ];

    $critic = Mockery::mock(RfpProposalCritic::class);
    $critic->shouldReceive('critique')->once()->andReturn($critique);
    $critic->shouldReceive('revise')->once()->andReturn($proposal);

    $slack = Mockery::mock(RfpSlackNotifier::class);
    $slack->shouldReceive('notifyProposalReady')->once();

    (new CritiqueRfpProposalJob($proposal->id))->handle($critic, $slack);

    expect($proposal->fresh()->critique_revised)->toBeTrue()
        ->and($proposal->fresh()->critique_blocker_count)->toBe(1);
});

it('skips when proposal cannot be found', function () {
    $critic = Mockery::mock(RfpProposalCritic::class);
    $critic->shouldNotReceive('critique');

    $slack = Mockery::mock(RfpSlackNotifier::class);
    $slack->shouldNotReceive('notifyProposalReady');

    (new CritiqueRfpProposalJob(999999))->handle($critic, $slack);

    expect(true)->toBeTrue();
});
