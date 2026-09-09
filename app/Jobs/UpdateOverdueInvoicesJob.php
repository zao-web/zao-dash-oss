<?php

namespace App\Jobs;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Automatically marks unpaid invoices as overdue when past their due date.
 * Runs daily via scheduler.
 */
class UpdateOverdueInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // First, fix any invoices that are marked as overdue but have been paid
        $this->fixPaidInvoicesWithWrongStatus();

        // Then mark unpaid invoices as overdue
        $unpaidStatuses = [
            Invoice::STATUS_SENT,
            Invoice::STATUS_VIEWED,
            Invoice::STATUS_PARTIAL,
        ];

        $overdueInvoices = Invoice::whereIn('status', $unpaidStatuses)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now()->startOfDay())
            ->where('amount_due', '>', 0) // Only mark as overdue if there's still money owed
            ->get();

        $count = 0;
        foreach ($overdueInvoices as $invoice) {
            $invoice->update(['status' => Invoice::STATUS_OVERDUE]);
            $count++;

            Log::info('Invoice marked as overdue', [
                'invoice_id' => $invoice->id,
                'number' => $invoice->number,
                'due_date' => $invoice->due_date->format('Y-m-d'),
                'days_overdue' => $invoice->days_overdue,
            ]);
        }

        if ($count > 0) {
            Log::info('Overdue invoice update complete', ['count' => $count]);
        }
    }

    /**
     * Fix invoices that are marked as overdue/sent/viewed but have actually been paid.
     */
    protected function fixPaidInvoicesWithWrongStatus(): void
    {
        $wrongStatusInvoices = Invoice::whereIn('status', [
            Invoice::STATUS_SENT,
            Invoice::STATUS_VIEWED,
            Invoice::STATUS_PARTIAL,
            Invoice::STATUS_OVERDUE,
        ])
            ->where('amount_due', '<=', 0)
            ->where('total', '>', 0)
            ->get();

        foreach ($wrongStatusInvoices as $invoice) {
            $invoice->update([
                'status' => Invoice::STATUS_PAID,
                'paid_at' => $invoice->paid_at ?? now(),
            ]);

            Log::info('Fixed invoice status to paid', [
                'invoice_id' => $invoice->id,
                'number' => $invoice->number,
                'previous_status' => $invoice->getOriginal('status'),
            ]);
        }

        if ($wrongStatusInvoices->count() > 0) {
            Log::info('Fixed paid invoices with wrong status', [
                'count' => $wrongStatusInvoices->count(),
            ]);
        }
    }
}
