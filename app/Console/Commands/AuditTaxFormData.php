<?php

namespace App\Console\Commands;

use App\Models\TaxProfile;
use App\Services\Tax\TaxReturnPreparationService;
use Illuminate\Console\Command;

class AuditTaxFormData extends Command
{
    protected $signature = 'tax:audit {year=2025} {--user=1}';

    protected $description = 'Audit the draft tax return computation, showing data sources and flagging discrepancies';

    public function handle(TaxReturnPreparationService $taxReturnPreparationService): int
    {
        $year = (int) $this->argument('year');
        $userId = (int) $this->option('user');

        $profile = TaxProfile::where('user_id', $userId)->forYear($year)->first();

        if (! $profile) {
            $this->error("No tax profile found for user {$userId}, year {$year}.");

            return self::FAILURE;
        }

        $this->info("Auditing tax computation for {$profile->entity_name} ({$year})...\n");

        $packet = $taxReturnPreparationService->build($userId, $year);

        $draft = $packet['draft_return_computation'] ?? [];
        $bookkeeping = $packet['bookkeeping_readiness'] ?? [];
        $ledger = $bookkeeping['ledger_summary'] ?? [];
        $revenue = $packet['revenue_recognition'] ?? [];
        $ownerPayment = $packet['owner_payment_review'] ?? [];
        $taxComp = $draft['tax_computation'] ?? [];

        $grossReceipts = (float) ($draft['gross_receipts'] ?? 0);
        $businessExpenses = (float) ($draft['business_expenses'] ?? 0);
        $officerComp = (float) ($draft['officer_compensation'] ?? 0);
        $ordinaryBusinessIncome = (float) ($draft['ordinary_business_income'] ?? 0);
        $otherDeductions = max($businessExpenses - $officerComp, 0);

        // Data Source Selection
        $this->components->twoColumnDetail('<fg=cyan>Revenue Basis</>', $draft['basis_label'] ?? 'Unknown');
        $this->components->twoColumnDetail('<fg=cyan>Revenue Detail</>', $draft['basis_detail'] ?? '');
        $this->components->twoColumnDetail('<fg=cyan>Expense Source</>', data_get($bookkeeping, 'source_of_truth.label', 'Unknown'));
        $this->components->twoColumnDetail('<fg=cyan>Book Source Code</>', $ledger['book_source_code'] ?? 'unknown');
        $this->newLine();

        // All Available Revenue Sources
        $this->newLine();
        $this->info('── Revenue Sources ──');
        $this->table(
            ['Source', 'Amount'],
            [
                ['Recognized Revenue', $this->fmt((float) data_get($revenue, 'metrics.recognized_revenue_total', 0))],
                ['QuickBooks Book Income', $this->fmt((float) ($ledger['book_income'] ?? 0))],
                ['Internal Book Income', $this->fmt((float) ($ledger['internal_book_income'] ?? 0))],
                ['Bank Deposits', $this->fmt((float) ($ledger['bank_deposits'] ?? 0))],
                ['<fg=green>→ SELECTED: Gross Receipts</>', '<fg=green>'.$this->fmt($grossReceipts).'</>'],
            ],
        );

        // All Available Expense Sources
        $this->newLine();
        $this->info('── Expense Sources ──');
        $this->table(
            ['Source', 'Amount'],
            [
                ['QuickBooks Book Expenses', $this->fmt((float) ($ledger['book_expenses'] ?? 0))],
                ['Internal Book Expenses', $this->fmt((float) ($ledger['internal_book_expenses'] ?? 0))],
                ['Bank Outflows (total)', $this->fmt((float) ($ledger['bank_outflows'] ?? 0))],
                ['<fg=green>→ SELECTED: Business Expenses</>', '<fg=green>'.$this->fmt($businessExpenses).'</>'],
            ],
        );

        // 1120-S Line Items
        $this->newLine();
        $this->info('── Form 1120-S Line Items ──');
        $this->table(
            ['Line', 'Description', 'Amount'],
            [
                ['1a', 'Gross Receipts', $this->fmt($grossReceipts)],
                ['6', 'Gross Profit', $this->fmt($grossReceipts)],
                ['7', 'Officer Compensation', $this->fmt($officerComp)],
                ['19', 'Other Deductions', $this->fmt($otherDeductions)],
                ['20', 'Total Deductions', $this->fmt($officerComp + $otherDeductions)],
                ['21', 'Ordinary Business Income', $this->fmt($ordinaryBusinessIncome)],
            ],
        );

        // Personal Return Summary
        $this->newLine();
        $this->info('── Personal Return Summary ──');
        $this->table(
            ['Item', 'Amount'],
            [
                ['W-2 Wages', $this->fmt($officerComp)],
                ['Pass-Through Income', $this->fmt((float) ($draft['pass_through_income'] ?? 0))],
                ['Shareholder Distributions', $this->fmt((float) ($draft['shareholder_distributions'] ?? 0))],
                ['Itemized Deductions', $this->fmt((float) ($draft['itemized_deductions'] ?? 0))],
                ['QBI Deduction', $this->fmt((float) ($taxComp['qbi_deduction'] ?? 0))],
                ['AGI', $this->fmt((float) ($taxComp['agi'] ?? 0))],
                ['Federal Taxable Income', $this->fmt((float) ($taxComp['federal_taxable_income'] ?? 0))],
                ['Federal Tax', $this->fmt((float) ($taxComp['federal_tax'] ?? 0))],
                ['Oregon Tax', $this->fmt((float) ($taxComp['oregon_tax'] ?? 0))],
            ],
        );

        // Owner Payment Review
        $this->newLine();
        $this->info('── Owner Payment Review ──');
        $this->table(
            ['Item', 'Amount'],
            [
                ['Distributions', $this->fmt((float) data_get($ownerPayment, 'metrics.distribution_total', 0))],
                ['Owner Contributions', $this->fmt((float) data_get($ownerPayment, 'metrics.owner_contribution_total', 0))],
                ['Owner Loans', $this->fmt((float) data_get($ownerPayment, 'metrics.owner_loan_total', 0))],
                ['Reimbursements', $this->fmt((float) data_get($ownerPayment, 'metrics.owner_reimbursement_total', 0))],
                ['Potential Compensation', $this->fmt((float) data_get($ownerPayment, 'metrics.potential_compensation_total', 0))],
                ['Unresolved Items', (string) data_get($ownerPayment, 'metrics.unresolved_count', 0)],
            ],
        );

        // Bookkeeping Health
        $this->newLine();
        $this->info('── Bookkeeping Health ──');
        $this->components->twoColumnDetail('Status', $bookkeeping['status'] ?? 'unknown');
        $this->components->twoColumnDetail('Close Ready', ($bookkeeping['close_ready'] ?? false) ? 'Yes' : 'No');
        $this->components->twoColumnDetail('Tie-Out Passes', ($ledger['deposit_tie_out_passes'] ?? false) ? 'Yes' : 'No');
        $this->components->twoColumnDetail('Tie-Out Difference', $this->fmt((float) ($ledger['deposit_tie_out_difference'] ?? 0)));
        $this->components->twoColumnDetail('Uncategorized Inflows', (string) data_get($bookkeeping, 'metrics.uncategorized_inflow_count', 0));
        $this->components->twoColumnDetail('Uncategorized Outflows', (string) data_get($bookkeeping, 'metrics.uncategorized_outflow_count', 0));
        $this->newLine();

        // Sanity Checks
        $bankOutflows = (float) ($ledger['bank_outflows'] ?? 0);

        if ($businessExpenses > $grossReceipts) {
            $this->error(
                "Business expenses (\${$this->fmt($businessExpenses)}) EXCEED gross receipts (\${$this->fmt($grossReceipts)}) by \${$this->fmt($businessExpenses - $grossReceipts)}. This creates negative ordinary business income."
            );
        }

        if ($grossReceipts > 0 && ($businessExpenses / $grossReceipts) > 0.85) {
            $this->warn(
                'Expense-to-revenue ratio is '.round(($businessExpenses / $grossReceipts) * 100, 1).'%. Verify non-deductible outflows are not counted as expenses.'
            );
        }

        if ($bankOutflows > 0 && $businessExpenses > 0 && abs($businessExpenses - $bankOutflows) < 1000) {
            $this->error(
                "Business expenses (\${$this->fmt($businessExpenses)}) ≈ bank outflows (\${$this->fmt($bankOutflows)}). ALL outflows may be counted as expenses — including owner draws, distributions, and transfers."
            );
        }

        if ($officerComp > 0 && $grossReceipts > 0 && ($officerComp / $grossReceipts) < 0.10) {
            $this->warn(
                "Officer compensation (\${$this->fmt($officerComp)}) is only ".round(($officerComp / $grossReceipts) * 100, 1).'% of gross receipts. IRS may challenge as too low.'
            );
        }

        // Validation Gates
        $this->newLine();
        $this->info('── Validation Gates ──');
        $gates = collect($packet['validation_gates'] ?? []);
        $blocking = $gates->filter(fn (array $g): bool => $g['blocks_packet'] && $g['status'] !== 'passed');

        if ($blocking->isNotEmpty()) {
            $this->table(
                ['Gate', 'Status'],
                $blocking->map(fn (array $g): array => [
                    '<fg=red>'.$g['title'].'</>',
                    $g['status'],
                ])->values()->all(),
            );
        } else {
            $this->info('All gates passed.');
        }

        return self::SUCCESS;
    }

    protected function fmt(float $amount): string
    {
        if ($amount < 0) {
            return '('.number_format(abs($amount), 0).')';
        }

        return '$'.number_format($amount, 0);
    }
}
