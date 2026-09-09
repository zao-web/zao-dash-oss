<?php

namespace App\Services\Tax;

use App\Models\PayrollRun;
use App\Models\TaxProfile;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PayrollOperationsService
{
    public function __construct(
        protected PayrollTaxComplianceService $payrollTaxComplianceService,
    ) {}

    /**
     * @param  array<string, mixed>|null  $annualProjection
     * @return array<string, mixed>
     */
    public function summarize(int $userId, ?TaxProfile $profile, ?array $annualProjection, ?CarbonInterface $today = null): array
    {
        $today = $today ? Carbon::instance($today) : now();
        $compliance = $this->payrollTaxComplianceService->summarize($profile, $annualProjection, $today);

        if (! ($compliance['is_applicable'] ?? false)) {
            return [
                'is_applicable' => false,
                'has_plan' => false,
                'status' => 'not_applicable',
                'status_label' => 'Not applicable',
                'summary' => $compliance['next_action'] ?? 'Payroll operations are not required.',
                'next_action' => $compliance['next_action'] ?? 'Payroll operations are not required.',
                'can_generate_plan' => false,
                'planned_run_count' => 0,
                'completed_run_count' => 0,
                'pending_federal_deposit_count' => 0,
                'pending_oregon_deposit_count' => 0,
                'next_run' => null,
                'next_federal_deposit' => null,
                'next_oregon_deposit' => null,
                'operations' => [],
            ];
        }

        $runs = collect();

        if ($profile instanceof TaxProfile) {
            $runs = PayrollRun::query()
                ->where('user_id', $userId)
                ->where('tax_year', (int) $profile->tax_year)
                ->orderBy('period_month')
                ->get();
        }

        $operations = $runs
            ->map(fn (PayrollRun $run): array => $this->mapRun($run, $today))
            ->all();
        $operationCollection = collect($operations);
        $nextRun = $operationCollection
            ->first(fn (array $run): bool => ($run['status'] ?? null) !== PayrollRun::STATUS_COMPLETED);
        $nextFederalDeposit = $operationCollection
            ->first(fn (array $run): bool => ($run['federal_deposit_status'] ?? null) !== PayrollRun::DEPOSIT_STATUS_COMPLETED);
        $nextOregonDeposit = $operationCollection
            ->first(fn (array $run): bool => ($run['oregon_deposit_status'] ?? null) !== PayrollRun::DEPOSIT_STATUS_COMPLETED);
        $hasPlan = $operationCollection->isNotEmpty();
        $needsActualWages = (string) ($compliance['status'] ?? '') === 'needs_actual_wages';

        return [
            'is_applicable' => true,
            'has_plan' => $hasPlan,
            'status' => $needsActualWages
                ? ($hasPlan ? 'needs_reset' : 'needs_actual_wages')
                : ($hasPlan ? 'planned' : 'needs_plan'),
            'status_label' => $needsActualWages
                ? ($hasPlan ? 'Needs reset' : 'Needs actual wages')
                : ($hasPlan ? 'Planned' : 'Needs plan'),
            'summary' => $needsActualWages
                ? ($hasPlan
                    ? 'This payroll plan was generated from a salary target, not confirmed wages paid. Walk it back before filing.'
                    : 'No actual W-2 wages are recorded yet, so payroll runs should not be generated from the salary target alone.')
                : ($hasPlan
                ? 'Payroll runs and deposit checkpoints are staged month by month.'
                : 'Generate the payroll operating plan so pay runs and deposit checkpoints are tracked explicitly.'),
            'next_action' => $needsActualWages
                ? (($compliance['next_action'] ?? 'Record actual wages paid before generating payroll operations.'))
                : ($hasPlan
                ? ($this->nextAction($nextRun, $nextFederalDeposit, $nextOregonDeposit) ?? 'Keep payroll runs and deposits up to date as each month closes.')
                : 'Generate the payroll operating plan before relying on payroll compliance dates alone.'),
            'can_generate_plan' => ! $needsActualWages && (bool) ($compliance['can_generate_forms'] ?? false),
            'planned_run_count' => $operationCollection->count(),
            'completed_run_count' => $operationCollection
                ->where('status', PayrollRun::STATUS_COMPLETED)
                ->count(),
            'pending_federal_deposit_count' => $operationCollection
                ->reject(fn (array $run): bool => ($run['federal_deposit_status'] ?? null) === PayrollRun::DEPOSIT_STATUS_COMPLETED)
                ->count(),
            'pending_oregon_deposit_count' => $operationCollection
                ->reject(fn (array $run): bool => ($run['oregon_deposit_status'] ?? null) === PayrollRun::DEPOSIT_STATUS_COMPLETED)
                ->count(),
            'next_run' => $nextRun,
            'next_federal_deposit' => $nextFederalDeposit,
            'next_oregon_deposit' => $nextOregonDeposit,
            'operations' => $operations,
        ];
    }

    /**
     * @param  array<string, mixed>  $annualProjection
     * @return Collection<int, PayrollRun>
     */
    public function generatePlan(int $userId, TaxProfile $profile, array $annualProjection, ?CarbonInterface $today = null): Collection
    {
        $today = $today ? Carbon::instance($today) : now();
        $compliance = $this->payrollTaxComplianceService->summarize($profile, $annualProjection, $today);

        if (! ($compliance['is_applicable'] ?? false) || ! ($compliance['can_generate_forms'] ?? false)) {
            throw ValidationException::withMessages([
                'year' => $compliance['next_action'] ?? 'Payroll operations cannot be generated yet.',
            ]);
        }

        $payRunsPerYear = (int) ($compliance['pay_runs_per_year'] ?? 0);

        if ($payRunsPerYear <= 0) {
            throw ValidationException::withMessages([
                'year' => 'Payroll operations cannot be generated without a valid pay cadence.',
            ]);
        }

        $runs = collect();

        foreach (range(1, $payRunsPerYear) as $index) {
            $federalSchedule = $compliance['federal_deposit_schedule'][$index - 1] ?? null;
            $oregonSchedule = $compliance['oregon_deposit_schedule'][$index - 1] ?? null;

            if (! is_array($federalSchedule) || ! is_array($oregonSchedule)) {
                continue;
            }

            $periodMonth = (int) ($federalSchedule['period_month'] ?? $index);
            $existingRun = PayrollRun::query()
                ->where('user_id', $userId)
                ->where('tax_year', (int) $profile->tax_year)
                ->where('period_month', $periodMonth)
                ->where('pay_frequency', (string) ($compliance['pay_frequency'] ?? 'monthly'))
                ->first();

            $payload = [
                'tax_profile_id' => $profile->id,
                'gross_pay' => round((float) ($compliance['per_run_salary'] ?? 0), 2),
                'federal_withholding' => round((float) ($compliance['per_run_federal_withholding_target'] ?? 0), 2),
                'oregon_withholding' => round((float) ($compliance['per_run_oregon_withholding_target'] ?? 0), 2),
                'employee_fica' => round((float) ($compliance['per_run_employee_fica'] ?? 0), 2),
                'employer_fica' => round((float) ($compliance['per_run_employer_fica'] ?? 0), 2),
                'statewide_transit_tax' => round((float) ($compliance['per_run_statewide_transit_tax'] ?? 0), 2),
                'net_pay' => round(
                    (float) ($compliance['per_run_salary'] ?? 0)
                    - (float) ($compliance['per_run_federal_withholding_target'] ?? 0)
                    - (float) ($compliance['per_run_oregon_withholding_target'] ?? 0)
                    - (float) ($compliance['per_run_employee_fica'] ?? 0)
                    - (float) ($compliance['per_run_statewide_transit_tax'] ?? 0),
                    2,
                ),
                'federal_deposit_amount' => round(
                    (float) ($compliance['per_run_federal_withholding_target'] ?? 0)
                    + (float) ($compliance['per_run_employee_fica'] ?? 0)
                    + (float) ($compliance['per_run_employer_fica'] ?? 0),
                    2,
                ),
                'oregon_deposit_amount' => round(
                    (float) ($compliance['per_run_oregon_withholding_target'] ?? 0)
                    + (float) ($compliance['per_run_statewide_transit_tax'] ?? 0),
                    2,
                ),
                'pay_date' => $this->scheduleDate($federalSchedule['pay_date_iso'] ?? null, $federalSchedule['pay_date'] ?? null),
                'federal_deposit_due' => $this->scheduleDate($federalSchedule['deposit_due_iso'] ?? null, $federalSchedule['deposit_due'] ?? null),
                'oregon_deposit_due' => $this->scheduleDate($oregonSchedule['deposit_due_iso'] ?? null, $oregonSchedule['deposit_due'] ?? null),
                'snapshot' => [
                    'annual_salary_source' => $compliance['annual_salary_source'] ?? null,
                    'deposit_schedule_type' => $compliance['deposit_schedule_type'] ?? null,
                ],
            ];

            if ($existingRun instanceof PayrollRun) {
                if ($existingRun->completed_at !== null) {
                    $runs->push($existingRun);

                    continue;
                }

                $existingRun->fill($payload);
                $existingRun->save();
                $runs->push($existingRun);

                continue;
            }

            $runs->push(PayrollRun::create([
                'user_id' => $userId,
                'tax_profile_id' => $profile->id,
                'tax_year' => (int) $profile->tax_year,
                'period_month' => $periodMonth,
                'pay_frequency' => (string) ($compliance['pay_frequency'] ?? 'monthly'),
                'status' => PayrollRun::STATUS_PLANNED,
                'federal_deposit_status' => PayrollRun::DEPOSIT_STATUS_PENDING,
                'oregon_deposit_status' => PayrollRun::DEPOSIT_STATUS_PENDING,
                ...$payload,
            ]));
        }

        return $runs->sortBy('period_month')->values();
    }

    public function updateStatus(PayrollRun $run, string $action): PayrollRun
    {
        match ($action) {
            'complete_run' => $run->update([
                'status' => PayrollRun::STATUS_COMPLETED,
                'completed_at' => now(),
            ]),
            'reopen_run' => $run->update([
                'status' => PayrollRun::STATUS_PLANNED,
                'completed_at' => null,
            ]),
            'complete_federal_deposit' => $run->update([
                'federal_deposit_status' => PayrollRun::DEPOSIT_STATUS_COMPLETED,
                'federal_deposit_completed_at' => now(),
            ]),
            'reopen_federal_deposit' => $run->update([
                'federal_deposit_status' => PayrollRun::DEPOSIT_STATUS_PENDING,
                'federal_deposit_completed_at' => null,
            ]),
            'complete_oregon_deposit' => $run->update([
                'oregon_deposit_status' => PayrollRun::DEPOSIT_STATUS_COMPLETED,
                'oregon_deposit_completed_at' => now(),
            ]),
            'reopen_oregon_deposit' => $run->update([
                'oregon_deposit_status' => PayrollRun::DEPOSIT_STATUS_PENDING,
                'oregon_deposit_completed_at' => null,
            ]),
            default => throw ValidationException::withMessages([
                'action' => 'Unsupported payroll status update.',
            ]),
        };

        return $run->refresh();
    }

    public function resetPlan(int $userId, int $year): int
    {
        return PayrollRun::query()
            ->where('user_id', $userId)
            ->where('tax_year', $year)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapRun(PayrollRun $run, Carbon $today): array
    {
        $runStatusLabel = $run->status === PayrollRun::STATUS_COMPLETED
            ? 'Run completed'
            : ($run->pay_date->isPast() ? 'Run due' : 'Planned');
        $federalDepositStatusLabel = $run->federal_deposit_status === PayrollRun::DEPOSIT_STATUS_COMPLETED
            ? 'Federal deposit recorded'
            : ($run->federal_deposit_due?->isPast() ? 'Federal deposit due' : 'Federal deposit pending');
        $oregonDepositStatusLabel = $run->oregon_deposit_status === PayrollRun::DEPOSIT_STATUS_COMPLETED
            ? 'Oregon deposit recorded'
            : ($run->oregon_deposit_due?->isPast() ? 'Oregon deposit due' : 'Oregon deposit pending');

        return [
            'id' => $run->id,
            'period_month' => $run->period_month,
            'period_label' => Carbon::create($run->tax_year, $run->period_month, 1)->format('F Y'),
            'pay_date' => $run->pay_date->format('M d, Y'),
            'pay_date_iso' => $run->pay_date->toDateString(),
            'gross_pay' => (float) $run->gross_pay,
            'net_pay' => (float) $run->net_pay,
            'federal_deposit_amount' => (float) $run->federal_deposit_amount,
            'oregon_deposit_amount' => (float) $run->oregon_deposit_amount,
            'federal_deposit_due' => $run->federal_deposit_due?->format('M d, Y'),
            'oregon_deposit_due' => $run->oregon_deposit_due?->format('M d, Y'),
            'status' => $run->status,
            'status_label' => $runStatusLabel,
            'federal_deposit_status' => $run->federal_deposit_status,
            'federal_deposit_status_label' => $federalDepositStatusLabel,
            'oregon_deposit_status' => $run->oregon_deposit_status,
            'oregon_deposit_status_label' => $oregonDepositStatusLabel,
            'completed_at' => $run->completed_at?->format('M d, Y g:i A'),
            'federal_deposit_completed_at' => $run->federal_deposit_completed_at?->format('M d, Y g:i A'),
            'oregon_deposit_completed_at' => $run->oregon_deposit_completed_at?->format('M d, Y g:i A'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $run
     * @param  array<string, mixed>|null  $federalDeposit
     * @param  array<string, mixed>|null  $oregonDeposit
     */
    protected function nextAction(?array $run, ?array $federalDeposit, ?array $oregonDeposit): ?string
    {
        if (is_array($run) && ($run['status'] ?? null) !== PayrollRun::STATUS_COMPLETED) {
            return "Run {$run['period_label']} payroll and record it once wages are paid.";
        }

        if (is_array($federalDeposit) && ($federalDeposit['federal_deposit_status'] ?? null) !== PayrollRun::DEPOSIT_STATUS_COMPLETED) {
            return "Record the federal payroll deposit due {$federalDeposit['federal_deposit_due']}.";
        }

        if (is_array($oregonDeposit) && ($oregonDeposit['oregon_deposit_status'] ?? null) !== PayrollRun::DEPOSIT_STATUS_COMPLETED) {
            return "Record the Oregon payroll deposit due {$oregonDeposit['oregon_deposit_due']}.";
        }

        return null;
    }

    protected function scheduleDate(?string $isoDate, ?string $fallbackLabel): string
    {
        if (is_string($isoDate) && $isoDate !== '') {
            return $isoDate;
        }

        return Carbon::parse((string) $fallbackLabel)->toDateString();
    }
}
