<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\HarvestInvoice;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use Illuminate\Console\Command;

class MigrateHarvestInvoicesCommand extends Command
{
    protected $signature = 'invoices:migrate-from-harvest
                            {--dry-run : Show what would be migrated without migrating}
                            {--skip-existing : Skip invoices that already exist (by harvest_invoice_id)}';

    protected $description = 'Convert all HarvestInvoice records to native Invoice records for unified reporting';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $skipExisting = $this->option('skip-existing');

        $this->info('=== Migrate Harvest Invoices to Native System ===');
        $this->newLine();

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No data will be created');
            $this->newLine();
        }

        // Get all Harvest invoices
        $harvestInvoices = HarvestInvoice::orderBy('issue_date')->get();
        $this->info("Found {$harvestInvoices->count()} Harvest invoices to migrate");

        // Check existing
        $existingCount = Invoice::whereNotNull('harvest_invoice_id')->count();
        $this->info("Already migrated: {$existingCount}");
        $this->newLine();

        $stats = [
            'created' => 0,
            'skipped_existing' => 0,
            'skipped_no_client' => 0,
            'payments_created' => 0,
        ];

        $bar = $this->output->createProgressBar($harvestInvoices->count());
        $bar->start();

        foreach ($harvestInvoices as $harvest) {
            // Check if already migrated
            if ($skipExisting && Invoice::where('harvest_invoice_id', $harvest->harvest_id)->exists()) {
                $stats['skipped_existing']++;
                $bar->advance();

                continue;
            }

            // Find the client
            $client = Client::where('harvest_client_id', $harvest->client_harvest_id)->first();

            if (! $client) {
                $stats['skipped_no_client']++;
                $bar->advance();

                continue;
            }

            // Map Harvest state to native status
            $status = $this->mapStatus($harvest->state, $harvest);

            if (! $dryRun) {
                // Calculate amounts
                $subtotal = $harvest->amount - ($harvest->tax_amount ?? 0) - ($harvest->tax2_amount ?? 0);
                $amountPaid = $harvest->amount - ($harvest->due_amount ?? 0);
                $amountDue = $harvest->due_amount ?? 0;

                // Create the native invoice
                $invoice = Invoice::updateOrCreate(
                    ['harvest_invoice_id' => $harvest->harvest_id],
                    [
                        'client_id' => $client->id,
                        'number' => $harvest->number ?? 'H-'.$harvest->harvest_id,
                        'subject' => $harvest->subject,
                        'notes' => $harvest->notes,
                        'status' => $status,
                        'subtotal' => $subtotal,
                        'tax_rate' => $harvest->tax ?? 0,
                        'tax_amount' => ($harvest->tax_amount ?? 0) + ($harvest->tax2_amount ?? 0),
                        'total' => $harvest->amount,
                        'amount_paid' => $amountPaid,
                        'amount_due' => $amountDue,
                        'issue_date' => $harvest->issue_date,
                        'due_date' => $harvest->due_date,
                        'sent_at' => $harvest->sent_at,
                        'viewed_at' => null, // Harvest doesn't track this
                        'paid_at' => $harvest->paid_at ?? $harvest->paid_date,
                        'payment_terms' => $harvest->payment_term,
                        'currency' => $harvest->currency ?? 'USD',
                    ]
                );

                if ($invoice->lines()->count() === 0 && $harvest->amount > 0) {
                    InvoiceLine::withoutEvents(function () use ($invoice, $harvest, $subtotal) {
                        $this->createLineItemsFromHarvest($invoice, $harvest, $subtotal);
                    });
                }

                // If paid, create a payment record
                if ($status === 'paid' && $harvest->amount > 0) {
                    $existingPayment = Payment::where('invoice_id', $invoice->id)
                        ->where('reference', 'LIKE', 'Harvest Import%')
                        ->first();

                    if (! $existingPayment) {
                        // Use withoutEvents to prevent QuickBooks sync job from firing
                        Payment::withoutEvents(function () use ($invoice, $harvest) {
                            Payment::create([
                                'invoice_id' => $invoice->id,
                                'amount' => $harvest->amount,
                                'method' => 'other',
                                'status' => 'completed',
                                'payment_date' => $harvest->paid_at ?? $harvest->paid_date ?? $harvest->closed_at ?? $harvest->issue_date,
                                'reference' => 'Harvest Import #'.$harvest->harvest_id,
                                'notes' => 'Migrated from Harvest invoice',
                            ]);
                        });
                        $stats['payments_created']++;
                    }
                }
            }

            $stats['created']++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // Summary
        $this->info('=== Migration Summary ===');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Invoices Created/Updated', $stats['created']],
                ['Skipped (already exists)', $stats['skipped_existing']],
                ['Skipped (no matching client)', $stats['skipped_no_client']],
                ['Payment Records Created', $stats['payments_created']],
            ]
        );

        if ($stats['skipped_no_client'] > 0) {
            $this->newLine();
            $this->warn('Some invoices were skipped because no matching client was found.');
            $this->warn("Run 'harvest:full-import' first to sync all clients.");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a dry run. Run without --dry-run to actually migrate.');
        } else {
            $this->newLine();
            $this->info('✅ Migration complete! Reports will now include all historical data.');
        }

        return Command::SUCCESS;
    }

    protected function mapStatus(string $harvestState, HarvestInvoice $invoice): string
    {
        // Harvest states: draft, open, paid, closed
        return match ($harvestState) {
            'draft' => 'draft',
            'open' => $this->isOverdue($invoice) ? 'overdue' : 'sent',
            'paid' => 'paid',
            'closed' => 'paid', // Closed in Harvest means paid
            default => 'draft',
        };
    }

    protected function isOverdue(HarvestInvoice $invoice): bool
    {
        if (! $invoice->due_date) {
            return false;
        }

        return $invoice->due_date->isPast() && ($invoice->due_amount ?? 0) > 0;
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
            $description = $harvest->subject ?: 'Professional Services';
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
