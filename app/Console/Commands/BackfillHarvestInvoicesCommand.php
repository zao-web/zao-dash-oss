<?php

namespace App\Console\Commands;

use App\Models\HarvestInvoice;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use Illuminate\Console\Command;

class BackfillHarvestInvoicesCommand extends Command
{
    protected $signature = 'invoices:backfill-harvest
                            {--dry-run : Show what would be fixed without making changes}
                            {--invoice= : Fix a specific invoice by number}';

    protected $description = 'Fix existing migrated Harvest invoices: correct amount_due and add missing line items';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $specificInvoice = $this->option('invoice');

        $this->info('=== Backfill Harvest Invoices ===');
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
            $this->newLine();
        }

        $query = Invoice::whereNotNull('harvest_invoice_id')->with(['lines', 'client']);

        if ($specificInvoice) {
            $query->where('number', $specificInvoice);
        }

        $migratedInvoices = $query->get();

        if ($migratedInvoices->isEmpty()) {
            $this->info('No migrated invoices found.');

            return Command::SUCCESS;
        }

        $this->info("Found {$migratedInvoices->count()} migrated invoice(s) to check");
        $this->newLine();

        $stats = [
            'amount_due_fixed' => 0,
            'line_items_added' => 0,
            'already_correct' => 0,
        ];

        $issues = [];

        foreach ($migratedInvoices as $invoice) {
            $harvestInvoice = HarvestInvoice::where('harvest_id', $invoice->harvest_invoice_id)->first();

            if (! $harvestInvoice) {
                $this->warn("  Invoice #{$invoice->number}: No matching HarvestInvoice found (harvest_id: {$invoice->harvest_invoice_id})");

                continue;
            }

            $needsFix = false;
            $fixDetails = [];

            $expectedAmountDue = $invoice->status === Invoice::STATUS_PAID
                ? 0
                : ($harvestInvoice->due_amount ?? 0);
            $currentAmountDue = (float) $invoice->amount_due;

            $amountDueMismatch = abs($currentAmountDue - $expectedAmountDue) > 0.01;
            if ($amountDueMismatch) {
                $needsFix = true;
                $fixDetails[] = "amount_due: {$currentAmountDue} → {$expectedAmountDue}";
            }

            $hasLineItems = $invoice->lines->count() > 0;
            $missingLineItems = ! $hasLineItems && $invoice->total > 0;
            if ($missingLineItems) {
                $needsFix = true;
                $fixDetails[] = 'missing line items';
            }

            if ($needsFix) {
                $issues[] = [
                    'number' => $invoice->number,
                    'client' => $invoice->client->name ?? 'N/A',
                    'status' => $invoice->status,
                    'total' => '$'.number_format($invoice->total, 2),
                    'current_due' => '$'.number_format($currentAmountDue, 2),
                    'expected_due' => '$'.number_format($expectedAmountDue, 2),
                    'has_lines' => $hasLineItems ? 'Yes' : 'No',
                    'fixes' => implode(', ', $fixDetails),
                ];

                if (! $dryRun) {
                    if ($amountDueMismatch) {
                        $invoice->update(['amount_due' => $expectedAmountDue]);
                        $stats['amount_due_fixed']++;
                    }

                    if ($missingLineItems) {
                        $subtotal = $invoice->subtotal > 0 ? $invoice->subtotal : $invoice->total;

                        InvoiceLine::withoutEvents(function () use ($invoice, $harvestInvoice, $subtotal) {
                            $this->createLineItemsFromHarvest($invoice, $harvestInvoice, $subtotal);
                        });
                        $stats['line_items_added']++;
                    }
                }
            } else {
                $stats['already_correct']++;
            }
        }

        if (! empty($issues)) {
            $this->info('Issues found in '.count($issues).' invoice(s):');
            $this->newLine();

            $this->table(
                ['Invoice #', 'Client', 'Status', 'Total', 'Current Due', 'Expected Due', 'Has Lines', 'Fixes'],
                $issues
            );
        } else {
            $this->info('All invoices are already correct!');
        }

        $this->newLine();
        $this->info('=== Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Amount Due Fixed', $stats['amount_due_fixed']],
                ['Line Items Added', $stats['line_items_added']],
                ['Already Correct', $stats['already_correct']],
            ]
        );

        if ($dryRun && ! empty($issues)) {
            $this->newLine();
            $this->warn('This was a dry run. Run without --dry-run to apply fixes.');
        } elseif (! $dryRun && ($stats['amount_due_fixed'] > 0 || $stats['line_items_added'] > 0)) {
            $this->newLine();
            $this->info('✅ Backfill complete!');
        }

        return Command::SUCCESS;
    }

    protected function createLineItemsFromHarvest(Invoice $invoice, HarvestInvoice $harvest, float $subtotal): void
    {
        $harvestLineItems = $harvest->line_items;

        if (! empty($harvestLineItems) && is_array($harvestLineItems)) {
            foreach ($harvestLineItems as $index => $item) {
                $invoice->lines()->create([
                    'type' => $this->mapLineItemType($item['kind'] ?? 'Service'),
                    'description' => $item['description'] ?? $item['kind'] ?? 'Line item',
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'] ?? $item['amount'] ?? 0,
                    'amount' => $item['amount'] ?? 0,
                    'taxable' => $item['taxed'] ?? true,
                    'sort_order' => $index,
                ]);
            }
        } else {
            $description = $harvest->subject ?: $invoice->subject ?: 'Professional Services';
            $invoice->lines()->create([
                'type' => InvoiceLine::TYPE_FIXED,
                'description' => $description,
                'quantity' => 1,
                'unit_price' => $subtotal,
                'amount' => $subtotal,
                'taxable' => true,
                'sort_order' => 0,
            ]);
        }
    }

    protected function mapLineItemType(string $harvestKind): string
    {
        return match (strtolower($harvestKind)) {
            'service' => InvoiceLine::TYPE_TIME,
            'product' => InvoiceLine::TYPE_FIXED,
            'expense' => InvoiceLine::TYPE_EXPENSE,
            default => InvoiceLine::TYPE_FIXED,
        };
    }
}
