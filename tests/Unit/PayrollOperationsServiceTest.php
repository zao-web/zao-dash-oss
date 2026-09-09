<?php

use App\Models\PayrollRun;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Tax\PayrollOperationsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates a month-by-month payroll operating plan', function () {
    $user = User::factory()->create();
    $profile = TaxProfile::create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'filing_status' => 'single',
        'entity_type' => 's_corp',
        'reasonable_salary' => 48000,
        'w2_wages_paid' => 48000,
    ]);

    $projection = [
        'tax_computation' => [
            'salary' => 48000,
            'federal_tax' => 18000,
            'oregon_tax' => 5400,
        ],
        'payments_made' => [
            'federal' => 0,
            'state_or' => 0,
        ],
    ];

    $runs = app(PayrollOperationsService::class)->generatePlan(
        userId: $user->id,
        profile: $profile,
        annualProjection: $projection,
        today: Carbon::parse('2026-04-12 09:00:00'),
    );

    expect($runs)->toHaveCount(12)
        ->and($runs->first()->gross_pay)->toBe('4000.00')
        ->and($runs->first()->federal_deposit_status)->toBe(PayrollRun::DEPOSIT_STATUS_PENDING)
        ->and($runs->first()->oregon_deposit_status)->toBe(PayrollRun::DEPOSIT_STATUS_PENDING);

    $summary = app(PayrollOperationsService::class)->summarize(
        userId: $user->id,
        profile: $profile,
        annualProjection: $projection,
        today: Carbon::parse('2026-04-12 09:00:00'),
    );

    expect($summary['has_plan'])->toBeTrue()
        ->and($summary['planned_run_count'])->toBe(12)
        ->and($summary['pending_federal_deposit_count'])->toBe(12)
        ->and($summary['pending_oregon_deposit_count'])->toBe(12)
        ->and($summary['next_run']['period_label'])->toBe('January 2025');
});

it('tracks payroll run and deposit completion state', function () {
    $user = User::factory()->create();
    $profile = TaxProfile::create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'filing_status' => 'single',
        'entity_type' => 's_corp',
        'reasonable_salary' => 48000,
        'w2_wages_paid' => 48000,
    ]);

    $run = PayrollRun::factory()->create([
        'user_id' => $user->id,
        'tax_profile_id' => $profile->id,
        'tax_year' => 2025,
        'period_month' => 1,
        'pay_frequency' => 'monthly',
        'pay_date' => '2025-01-31',
        'federal_deposit_due' => '2025-02-15',
        'oregon_deposit_due' => '2025-02-15',
    ]);

    $service = app(PayrollOperationsService::class);
    $run = $service->updateStatus($run, 'complete_run');
    $run = $service->updateStatus($run, 'complete_federal_deposit');
    $run = $service->updateStatus($run, 'complete_oregon_deposit');

    expect($run->status)->toBe(PayrollRun::STATUS_COMPLETED)
        ->and($run->federal_deposit_status)->toBe(PayrollRun::DEPOSIT_STATUS_COMPLETED)
        ->and($run->oregon_deposit_status)->toBe(PayrollRun::DEPOSIT_STATUS_COMPLETED)
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->federal_deposit_completed_at)->not->toBeNull()
        ->and($run->oregon_deposit_completed_at)->not->toBeNull();
});

it('does not generate a payroll plan when actual wages have not been recorded', function () {
    $user = User::factory()->create();
    $profile = TaxProfile::create([
        'user_id' => $user->id,
        'tax_year' => 2025,
        'filing_status' => 'single',
        'entity_type' => 's_corp',
        'reasonable_salary' => 48000,
        'w2_wages_paid' => 0,
    ]);

    $summary = app(PayrollOperationsService::class)->summarize(
        userId: $user->id,
        profile: $profile,
        annualProjection: [
            'tax_computation' => [
                'salary' => 48000,
                'federal_tax' => 18000,
                'oregon_tax' => 5400,
            ],
            'payments_made' => [
                'federal' => 0,
                'state_or' => 0,
            ],
        ],
        today: Carbon::parse('2026-04-12 09:00:00'),
    );

    expect($summary['has_plan'])->toBeFalse()
        ->and($summary['status'])->toBe('needs_actual_wages')
        ->and($summary['can_generate_plan'])->toBeFalse()
        ->and($summary['next_action'])->toContain('Record actual 2025 W-2 wages paid');
});
