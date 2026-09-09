<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

class FixInvoiceStatuses extends Command
{
    protected $signature = 'invoices:fix-statuses {--dry-run : Show what would be changed without making changes}';

    protected $description = 'Fix invoice statuses and totals that are out of sync';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN - No changes will be made');
        }

        // First, find invoices with data integrity issues (amount_due doesn't match total - paid)
        $this->fixDataIntegrityIssues($dryRun);

        // Then find invoices that should be marked as paid (have actual payments)
        $this->fixPaidInvoicesWithWrongStatus($dryRun);

        return Command::SUCCESS;
    }

    protected function fixDataIntegrityIssues(bool $dryRun): void
    {
        $this->info('Checking for data integrity issues...');

        // Find invoices where amount_due doesn't make sense
        $suspectInvoices = Invoice::whereIn('status', [
            Invoice::STATUS_SENT,
            Invoice::STATUS_VIEWED,
            Invoice::STATUS_PARTIAL,
            Invoice::STATUS_OVERDUE,
        ])
            ->where('total', '>', 0)
            ->where('amount_due', '<=', 0)
            ->where('amount_paid', '<=', 0) // No actual payments recorded
            ->get();

        if ($suspectInvoices->isEmpty()) {
            $this->info('No data integrity issues found.');
            $this->newLine();

            return;
        }

        $this->warn("Found {$suspectInvoices->count()} invoice(s) with data integrity issues:");
        $this->line('(These have Total > $0, but Due = $0 with no payments recorded)');
        $this->newLine();

        $table = [];
        foreach ($suspectInvoices as $invoice) {
            $table[] = [
                $invoice->number,
                $invoice->client->name ?? 'N/A',
                $invoice->status,
                '$'.number_format($invoice->total, 2),
                '$'.number_format($invoice->amount_paid, 2),
                '$'.number_format($invoice->amount_due, 2),
            ];
        }

        $this->table(
            ['Invoice #', 'Client', 'Status', 'Total', 'Paid', 'Due (wrong)'],
            $table
        );

        if (! $dryRun) {
            if ($this->confirm('Recalculate totals for these invoices to fix amount_due?')) {
                foreach ($suspectInvoices as $invoice) {
                    $oldDue = $invoice->amount_due;
                    $invoice->recalculateTotals();
                    $invoice->refresh();
                    $this->line("  #{$invoice->number}: Due was \${$oldDue}, now \${$invoice->amount_due}");
                }
                $this->newLine();
                $this->info('Data integrity issues fixed.');
            }
        }

        $this->newLine();
    }

    protected function fixPaidInvoicesWithWrongStatus(bool $dryRun): void
    {
        $this->info('Checking for paid invoices with wrong status...');

        // Find invoices that have actual payments and amount_due = 0
        $shouldBePaid = Invoice::whereIn('status', [
            Invoice::STATUS_SENT,
            Invoice::STATUS_VIEWED,
            Invoice::STATUS_PARTIAL,
            Invoice::STATUS_OVERDUE,
        ])
            ->where('amount_due', '<=', 0)
            ->where('amount_paid', '>', 0) // Has actual payments
            ->where('total', '>', 0)
            ->get();

        if ($shouldBePaid->isEmpty()) {
            $this->info('No paid invoices with wrong status found.');

            return;
        }

        $this->info("Found {$shouldBePaid->count()} invoice(s) that should be marked as paid:");
        $this->newLine();

        $table = [];
        foreach ($shouldBePaid as $invoice) {
            $table[] = [
                $invoice->number,
                $invoice->client->name ?? 'N/A',
                $invoice->status,
                '$'.number_format($invoice->total, 2),
                '$'.number_format($invoice->amount_paid, 2),
                '$'.number_format($invoice->amount_due, 2),
            ];
        }

        $this->table(
            ['Invoice #', 'Client', 'Current Status', 'Total', 'Paid', 'Due'],
            $table
        );

        if (! $dryRun) {
            if ($this->confirm('Update these invoices to "paid" status?')) {
                $updated = 0;
                foreach ($shouldBePaid as $invoice) {
                    $invoice->update([
                        'status' => Invoice::STATUS_PAID,
                        'paid_at' => $invoice->paid_at ?? now(),
                    ]);
                    $updated++;
                    $this->line("  Updated invoice #{$invoice->number}");
                }

                $this->newLine();
                $this->info("Successfully updated {$updated} invoice(s) to paid status.");
            }
        }
    }
}
