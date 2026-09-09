<?php

namespace App\Services\Tax;

use App\Models\TaxProfile;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class PayrollTaxComplianceService
{
    /**
     * @param  array<string, mixed>|null  $annualProjection
     * @return array{
     *     is_applicable: bool,
     *     status: string,
     *     status_label: string,
     *     pay_frequency: string,
     *     pay_frequency_label: string,
     *     pay_runs_per_year: int,
     *     annual_salary: float,
     *     annual_salary_source: string,
     *     per_run_salary: float,
     *     annual_federal_withholding_target: float,
     *     annual_oregon_withholding_target: float,
     *     per_run_federal_withholding_target: float,
     *     per_run_oregon_withholding_target: float,
     *     annual_employee_fica: float,
     *     annual_employer_fica: float,
     *     per_run_employee_fica: float,
     *     per_run_employer_fica: float,
     *     annual_statewide_transit_tax: float,
     *     deposit_schedule_type: string,
     *     deposit_schedule_label: string,
     *     federal_deposit_schedule: array<int, array{month: string, pay_date: string, deposit_due: string, amount: float, status: string, status_label: string}>,
     *     oregon_deposit_schedule: array<int, array{month: string, pay_date: string, deposit_due: string, amount: float, status: string, status_label: string}>,
     *     filing_deadlines: array<int, array{label: string, due_date: string}>,
     *     assumptions: array<int, string>,
     *     next_action: string,
     *     can_generate_forms: bool,
     * }
     */
    public function summarize(?TaxProfile $profile, ?array $annualProjection, ?CarbonInterface $today = null): array
    {
        if (! $profile || $profile->entity_type !== 's_corp') {
            return $this->notApplicable('Payroll compliance planning is only shown when the tax profile is marked as an S-corp.');
        }

        if ($annualProjection === null) {
            return $this->notReady('needs_projection', 'Needs projection', 'Refresh the live annual projection before building payroll compliance.', $profile);
        }

        $today = $today ? Carbon::instance($today) : now();
        $year = (int) $profile->tax_year;
        $filingStatus = (string) ($profile->filing_status ?? 'single');
        $actualWagesPaid = round((float) ($profile->w2_wages_paid ?? 0), 2);
        $annualSalary = $this->annualSalary($profile, $annualProjection);

        if ($actualWagesPaid <= 0) {
            return $this->notReady(
                'needs_actual_wages',
                'Needs actual wages',
                "Record actual {$year} W-2 wages paid before generating payroll filings. If no wages were paid, do not generate W-2, W-3, 941, 940, or Oregon payroll drafts from the salary plan alone.",
                $profile,
            );
        }

        if ($annualSalary <= 0) {
            return $this->notReady('needs_salary', 'Needs salary', 'Set a reasonable salary / W-2 amount before building payroll compliance.', $profile);
        }

        $taxComputation = $annualProjection['tax_computation'] ?? [];
        $paymentsMade = $annualProjection['payments_made'] ?? [];
        $fica = TaxBracketEngine::calculateSCorpFica($annualSalary, $year, $filingStatus);
        $annualFederalWithholdingTarget = max(0, round((float) ($taxComputation['federal_tax'] ?? 0) - (float) ($paymentsMade['federal'] ?? 0), 2));
        $annualOregonWithholdingTarget = max(0, round((float) ($taxComputation['oregon_tax'] ?? 0) - (float) ($paymentsMade['state_or'] ?? 0), 2));
        $annualStatewideTransitTax = round($annualSalary * 0.001, 2);
        $payRunsPerYear = 12;
        $perRunSalary = round($annualSalary / $payRunsPerYear, 2);
        $perRunFederalWithholdingTarget = round($annualFederalWithholdingTarget / $payRunsPerYear, 2);
        $perRunOregonWithholdingTarget = round($annualOregonWithholdingTarget / $payRunsPerYear, 2);
        $perRunEmployeeFica = round(((float) $fica['employee_ss'] + (float) $fica['employee_medicare'] + (float) $fica['additional_medicare']) / $payRunsPerYear, 2);
        $perRunEmployerFica = round(((float) $fica['employer_ss'] + (float) $fica['employer_medicare']) / $payRunsPerYear, 2);
        $perRunStatewideTransitTax = round($annualStatewideTransitTax / $payRunsPerYear, 2);
        $annualEmployeeFica = round((float) $fica['employee_ss'] + (float) $fica['employee_medicare'] + (float) $fica['additional_medicare'], 2);
        $annualEmployerFica = round((float) $fica['employer_ss'] + (float) $fica['employer_medicare'], 2);
        $annual941Tax = round($annualFederalWithholdingTarget + $annualEmployeeFica + $annualEmployerFica, 2);

        $depositScheduleType = $annual941Tax > 50000 ? 'review_required' : 'monthly_assumed';
        $depositScheduleLabel = $depositScheduleType === 'monthly_assumed' ? 'Monthly depositor assumed' : 'Review depositor cadence';

        return [
            'is_applicable' => true,
            'status' => $depositScheduleType === 'monthly_assumed' ? 'ready' : 'needs_review',
            'status_label' => $depositScheduleType === 'monthly_assumed' ? 'Ready' : 'Needs review',
            'pay_frequency' => 'monthly',
            'pay_frequency_label' => 'Monthly payroll',
            'pay_runs_per_year' => $payRunsPerYear,
            'annual_salary' => $annualSalary,
            'annual_salary_source' => $this->annualSalarySource($profile, $annualProjection, $annualSalary),
            'per_run_salary' => $perRunSalary,
            'annual_federal_withholding_target' => $annualFederalWithholdingTarget,
            'annual_oregon_withholding_target' => $annualOregonWithholdingTarget,
            'per_run_federal_withholding_target' => $perRunFederalWithholdingTarget,
            'per_run_oregon_withholding_target' => $perRunOregonWithholdingTarget,
            'annual_employee_fica' => $annualEmployeeFica,
            'annual_employer_fica' => $annualEmployerFica,
            'per_run_employee_fica' => $perRunEmployeeFica,
            'per_run_employer_fica' => $perRunEmployerFica,
            'annual_statewide_transit_tax' => $annualStatewideTransitTax,
            'per_run_statewide_transit_tax' => $perRunStatewideTransitTax,
            'deposit_schedule_type' => $depositScheduleType,
            'deposit_schedule_label' => $depositScheduleLabel,
            'federal_deposit_schedule' => $this->monthlyDepositSchedule(
                year: $year,
                monthlyAmount: round($annual941Tax / $payRunsPerYear, 2),
                today: $today,
            ),
            'oregon_deposit_schedule' => $this->monthlyDepositSchedule(
                year: $year,
                monthlyAmount: round(($annualOregonWithholdingTarget + $annualStatewideTransitTax) / $payRunsPerYear, 2),
                today: $today,
            ),
            'filing_deadlines' => $this->filingDeadlines($year),
            'assumptions' => $this->assumptions($depositScheduleType),
            'next_action' => $depositScheduleType === 'monthly_assumed'
                ? 'Run payroll on a monthly cadence, deposit withholding and employer taxes by the 15th of the following month, then file the quarterly and annual returns from the generated packet.'
                : 'Review the federal payroll deposit cadence before relying on the generated monthly deposit schedule.',
            'can_generate_forms' => true,
        ];
    }

    /**
     * @return array{
     *     is_applicable: bool,
     *     status: string,
     *     status_label: string,
     *     pay_frequency: string,
     *     pay_frequency_label: string,
     *     pay_runs_per_year: int,
     *     annual_salary: float,
     *     annual_salary_source: string,
     *     per_run_salary: float,
     *     annual_federal_withholding_target: float,
     *     annual_oregon_withholding_target: float,
     *     per_run_federal_withholding_target: float,
     *     per_run_oregon_withholding_target: float,
     *     annual_employee_fica: float,
     *     annual_employer_fica: float,
     *     per_run_employee_fica: float,
     *     per_run_employer_fica: float,
     *     annual_statewide_transit_tax: float,
     *     deposit_schedule_type: string,
     *     deposit_schedule_label: string,
     *     federal_deposit_schedule: array<int, array{month: string, pay_date: string, deposit_due: string, amount: float, status: string, status_label: string}>,
     *     oregon_deposit_schedule: array<int, array{month: string, pay_date: string, deposit_due: string, amount: float, status: string, status_label: string}>,
     *     filing_deadlines: array<int, array{label: string, due_date: string}>,
     *     assumptions: array<int, string>,
     *     next_action: string,
     *     can_generate_forms: bool,
     * }
     */
    protected function notApplicable(string $nextAction): array
    {
        return [
            'is_applicable' => false,
            'status' => 'not_applicable',
            'status_label' => 'Not applicable',
            'pay_frequency' => 'monthly',
            'pay_frequency_label' => 'Monthly payroll',
            'pay_runs_per_year' => 0,
            'annual_salary' => 0.0,
            'annual_salary_source' => 'Unavailable',
            'per_run_salary' => 0.0,
            'annual_federal_withholding_target' => 0.0,
            'annual_oregon_withholding_target' => 0.0,
            'per_run_federal_withholding_target' => 0.0,
            'per_run_oregon_withholding_target' => 0.0,
            'annual_employee_fica' => 0.0,
            'annual_employer_fica' => 0.0,
            'per_run_employee_fica' => 0.0,
            'per_run_employer_fica' => 0.0,
            'annual_statewide_transit_tax' => 0.0,
            'per_run_statewide_transit_tax' => 0.0,
            'deposit_schedule_type' => 'not_applicable',
            'deposit_schedule_label' => 'Not applicable',
            'federal_deposit_schedule' => [],
            'oregon_deposit_schedule' => [],
            'filing_deadlines' => [],
            'assumptions' => [],
            'next_action' => $nextAction,
            'can_generate_forms' => false,
        ];
    }

    /**
     * @return array{
     *     is_applicable: bool,
     *     status: string,
     *     status_label: string,
     *     pay_frequency: string,
     *     pay_frequency_label: string,
     *     pay_runs_per_year: int,
     *     annual_salary: float,
     *     annual_salary_source: string,
     *     per_run_salary: float,
     *     annual_federal_withholding_target: float,
     *     annual_oregon_withholding_target: float,
     *     per_run_federal_withholding_target: float,
     *     per_run_oregon_withholding_target: float,
     *     annual_employee_fica: float,
     *     annual_employer_fica: float,
     *     per_run_employee_fica: float,
     *     per_run_employer_fica: float,
     *     annual_statewide_transit_tax: float,
     *     deposit_schedule_type: string,
     *     deposit_schedule_label: string,
     *     federal_deposit_schedule: array<int, array{month: string, pay_date: string, deposit_due: string, amount: float, status: string, status_label: string}>,
     *     oregon_deposit_schedule: array<int, array{month: string, pay_date: string, deposit_due: string, amount: float, status: string, status_label: string}>,
     *     filing_deadlines: array<int, array{label: string, due_date: string}>,
     *     assumptions: array<int, string>,
     *     next_action: string,
     *     can_generate_forms: bool,
     * }
     */
    protected function notReady(string $status, string $statusLabel, string $nextAction, TaxProfile $profile): array
    {
        return [
            'is_applicable' => true,
            'status' => $status,
            'status_label' => $statusLabel,
            'pay_frequency' => 'monthly',
            'pay_frequency_label' => 'Payroll not ready',
            'pay_runs_per_year' => 0,
            'annual_salary' => 0.0,
            'annual_salary_source' => 'Unavailable',
            'per_run_salary' => 0.0,
            'annual_federal_withholding_target' => 0.0,
            'annual_oregon_withholding_target' => 0.0,
            'per_run_federal_withholding_target' => 0.0,
            'per_run_oregon_withholding_target' => 0.0,
            'annual_employee_fica' => 0.0,
            'annual_employer_fica' => 0.0,
            'per_run_employee_fica' => 0.0,
            'per_run_employer_fica' => 0.0,
            'annual_statewide_transit_tax' => 0.0,
            'per_run_statewide_transit_tax' => 0.0,
            'deposit_schedule_type' => 'not_ready',
            'deposit_schedule_label' => 'Waiting on inputs',
            'federal_deposit_schedule' => [],
            'oregon_deposit_schedule' => [],
            'filing_deadlines' => $this->filingDeadlines((int) $profile->tax_year),
            'assumptions' => [],
            'next_action' => $nextAction,
            'can_generate_forms' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $annualProjection
     */
    protected function annualSalary(TaxProfile $profile, array $annualProjection): float
    {
        return round((float) ($profile->w2_wages_paid ?? 0), 2);
    }

    /**
     * @param  array<string, mixed>  $annualProjection
     */
    protected function annualSalarySource(TaxProfile $profile, array $annualProjection, float $annualSalary): string
    {
        if ($annualSalary === (float) ($profile->w2_wages_paid ?? 0) && $annualSalary > 0) {
            return 'Stored W-2 wages paid';
        }

        if ($annualSalary === (float) ($annualProjection['tax_computation']['salary'] ?? 0) && $annualSalary > 0) {
            return 'Live annual projection salary';
        }

        return 'Reasonable salary setting';
    }

    /**
     * @return array<int, array{month: string, pay_date: string, deposit_due: string, amount: float, status: string, status_label: string}>
     */
    protected function monthlyDepositSchedule(int $year, float $monthlyAmount, Carbon $today): array
    {
        $schedule = [];

        for ($month = 1; $month <= 12; $month++) {
            $payDate = Carbon::create($year, $month, 1)->endOfMonth();
            $depositDue = $payDate->copy()->startOfMonth()->addMonth()->addDays(14);
            $status = $depositDue->lessThan($today->copy()->startOfDay()) ? 'past_due' : 'upcoming';

            $schedule[] = [
                'period_month' => $month,
                'month' => $payDate->format('F Y'),
                'pay_date' => $payDate->format('M d, Y'),
                'pay_date_iso' => $payDate->toDateString(),
                'deposit_due' => $depositDue->format('M d, Y'),
                'deposit_due_iso' => $depositDue->toDateString(),
                'amount' => $month === 12
                    ? round(max(0, ($monthlyAmount * 12) - ($monthlyAmount * 11)), 2)
                    : $monthlyAmount,
                'status' => $status,
                'status_label' => $status === 'past_due' ? 'Past due' : 'Upcoming',
            ];
        }

        return $schedule;
    }

    /**
     * @return array<int, array{label: string, due_date: string}>
     */
    protected function filingDeadlines(int $year): array
    {
        return [
            ['label' => 'Form 941 Q1', 'due_date' => "April 30, {$year}"],
            ['label' => 'Form 941 Q2', 'due_date' => "July 31, {$year}"],
            ['label' => 'Form 941 Q3', 'due_date' => "October 31, {$year}"],
            ['label' => 'Form 941 Q4', 'due_date' => 'January 31, '.($year + 1)],
            ['label' => 'Form 940', 'due_date' => 'January 31, '.($year + 1)],
            ['label' => 'Form W-2 / W-3', 'due_date' => 'January 31, '.($year + 1)],
            ['label' => 'Oregon Form OQ Q1', 'due_date' => "April 30, {$year}"],
            ['label' => 'Oregon Form OQ Q2', 'due_date' => "July 31, {$year}"],
            ['label' => 'Oregon Form OQ Q3', 'due_date' => "October 31, {$year}"],
            ['label' => 'Oregon Form OQ Q4', 'due_date' => 'January 31, '.($year + 1)],
            ['label' => 'Oregon Form OR-WR', 'due_date' => 'January 31, '.($year + 1)],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function assumptions(string $depositScheduleType): array
    {
        $assumptions = [
            'Assumes a monthly payroll cadence because no stored pay-run schedule exists yet.',
            'Uses IRS federal income tax and Oregon income tax from the live annual projection as withholding targets.',
            'Uses the Oregon statewide transit tax rate of 0.1% of wages.',
        ];

        if ($depositScheduleType === 'monthly_assumed') {
            $assumptions[] = 'Assumes a monthly federal depositor schedule because the projected annual Form 941 liability stays under the standard large-employer review threshold.';
        } else {
            $assumptions[] = 'Projected annual payroll taxes are large enough that the federal deposit cadence should be checked against the actual IRS lookback rules before relying on the monthly schedule.';
        }

        return $assumptions;
    }
}
