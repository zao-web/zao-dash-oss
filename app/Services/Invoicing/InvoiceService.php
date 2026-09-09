<?php

namespace App\Services\Invoicing;

use App\Jobs\SyncInvoiceToQuickBooksJob;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReminder;
use App\Models\Project;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public const GROUP_INDIVIDUAL = 'individual';  // Each time entry = one line

    public const GROUP_BY_PROJECT = 'project';     // Aggregate hours by project

    public const GROUP_BY_TASK = 'task';           // Aggregate hours by task

    public const GROUP_BY_DATE = 'date';           // Aggregate hours by date

    /**
     * Find all unbilled billable time entries for a client.
     */
    public function getUnbilledTimeEntries(
        Client $client,
        ?Project $project = null,
        ?Carbon $fromDate = null,
        ?Carbon $toDate = null
    ): Collection {
        $query = TimeEntry::where('client_id', $client->id)
            ->where('is_billable', true)
            ->where('is_billed', false)
            ->whereNotNull('hourly_rate')
            ->where('hours', '>', 0);

        if ($project) {
            $query->where('project_id', $project->id);
        }

        if ($fromDate) {
            $query->where('spent_date', '>=', $fromDate);
        }

        if ($toDate) {
            $query->where('spent_date', '<=', $toDate);
        }

        return $query->orderBy('spent_date')->orderBy('id')->get();
    }

    /**
     * Preview what an invoice would contain (without creating it).
     */
    public function previewInvoice(
        Client $client,
        Collection $timeEntries,
        string $groupMode = self::GROUP_INDIVIDUAL
    ): array {
        $lines = $this->buildLineItems($timeEntries, $groupMode);

        $subtotal = collect($lines)->sum('amount');
        $taxRate = $client->default_tax_rate ?? 0;
        $taxAmount = $taxRate > 0 ? $subtotal * ($taxRate / 100) : 0;
        $total = $subtotal + $taxAmount;

        return [
            'lines' => $lines,
            'subtotal' => round($subtotal, 2),
            'tax_rate' => $taxRate,
            'tax_amount' => round($taxAmount, 2),
            'total' => round($total, 2),
            'time_entry_count' => $timeEntries->count(),
            'total_hours' => $timeEntries->sum('hours'),
        ];
    }

    /**
     * Create an invoice from time entries.
     */
    public function createFromTimeEntries(
        Client $client,
        Collection $timeEntries,
        array $options = []
    ): Invoice {
        $groupMode = $options['group_mode'] ?? self::GROUP_INDIVIDUAL;
        $subject = $options['subject'] ?? null;
        $notes = $options['notes'] ?? $client->invoice_notes;
        $customDueDate = isset($options['due_date']);
        $dueDate = $options['due_date'] ?? $this->calculateDueDate($client);
        $projectId = $options['project_id'] ?? $timeEntries->first()?->project_id;
        $paymentTerms = $customDueDate
            ? self::derivePaymentTerms($dueDate, $client->payment_terms)
            : ($client->payment_terms ?? 'Net 30');

        return DB::transaction(function () use ($client, $timeEntries, $groupMode, $subject, $notes, $dueDate, $projectId, $paymentTerms) {
            // Create the invoice with retry for unique constraint violations
            $invoice = Invoice::createWithUniqueNumber([
                'client_id' => $client->id,
                'project_id' => $projectId,
                'subject' => $subject,
                'notes' => $notes,
                'internal_notes' => null,
                'status' => Invoice::STATUS_DRAFT,
                'subtotal' => 0,
                'tax_rate' => $client->default_tax_rate ?? 0,
                'tax_amount' => 0,
                'total' => 0,
                'amount_paid' => 0,
                'amount_due' => 0,
                'issue_date' => now(),
                'due_date' => $dueDate,
                'payment_terms' => $paymentTerms,
                'currency' => 'USD',
            ]);

            // Create line items
            $lines = $this->buildLineItems($timeEntries, $groupMode);
            $sortOrder = 0;

            foreach ($lines as $lineData) {
                $invoice->lines()->create([
                    'type' => InvoiceLine::TYPE_TIME,
                    'description' => $lineData['description'],
                    'details' => $lineData['details'] ?? null,
                    'quantity' => $lineData['quantity'],
                    'unit' => 'hours',
                    'unit_price' => $lineData['unit_price'],
                    'taxable' => true,
                    'time_entry_id' => $lineData['time_entry_id'] ?? null,
                    'project_id' => $lineData['project_id'] ?? null,
                    'service_date' => $lineData['service_date'] ?? null,
                    'sort_order' => $sortOrder++,
                ]);
            }

            // Mark time entries as billed
            TimeEntry::whereIn('id', $timeEntries->pluck('id'))
                ->update(['is_billed' => true]);

            // Refresh to get recalculated totals
            $invoice->refresh();

            return $invoice;
        });
    }

    /**
     * Add a fixed fee line item to an invoice.
     */
    public function addFixedFee(
        Invoice $invoice,
        string $description,
        float $amount,
        ?string $details = null,
        ?int $projectId = null
    ): InvoiceLine {
        $maxOrder = $invoice->lines()->max('sort_order') ?? -1;

        return $invoice->lines()->create([
            'type' => InvoiceLine::TYPE_FIXED,
            'description' => $description,
            'details' => $details,
            'quantity' => 1,
            'unit' => null,
            'unit_price' => $amount,
            'taxable' => true,
            'project_id' => $projectId,
            'sort_order' => $maxOrder + 1,
        ]);
    }

    /**
     * Add a discount to an invoice.
     */
    public function addDiscount(
        Invoice $invoice,
        string $description,
        float $amount
    ): InvoiceLine {
        $maxOrder = $invoice->lines()->max('sort_order') ?? -1;

        return $invoice->lines()->create([
            'type' => InvoiceLine::TYPE_DISCOUNT,
            'description' => $description,
            'quantity' => 1,
            'unit' => null,
            'unit_price' => abs($amount), // Will be negated by model
            'taxable' => false,
            'sort_order' => $maxOrder + 1,
        ]);
    }

    /**
     * Add an expense line item to an invoice.
     */
    public function addExpense(
        Invoice $invoice,
        string $description,
        float $amount,
        ?string $details = null,
        ?Carbon $serviceDate = null
    ): InvoiceLine {
        $maxOrder = $invoice->lines()->max('sort_order') ?? -1;

        return $invoice->lines()->create([
            'type' => InvoiceLine::TYPE_EXPENSE,
            'description' => $description,
            'details' => $details,
            'quantity' => 1,
            'unit' => null,
            'unit_price' => $amount,
            'taxable' => false, // Expenses typically not taxed
            'service_date' => $serviceDate,
            'sort_order' => $maxOrder + 1,
        ]);
    }

    /**
     * Mark an invoice as sent and schedule reminders.
     */
    public function markAsSent(Invoice $invoice): void
    {
        $invoice->update([
            'status' => Invoice::STATUS_SENT,
            'sent_at' => now(),
            // Clear the review hold — sending implies the operator approved.
            'pending_review' => false,
        ]);

        InvoiceReminder::scheduleForInvoice($invoice);

        // Sync to QuickBooks
        SyncInvoiceToQuickBooksJob::dispatch($invoice);
    }

    /**
     * Mark an invoice as viewed.
     */
    public function markAsViewed(Invoice $invoice): void
    {
        if (! $invoice->viewed_at) {
            $invoice->update([
                'status' => Invoice::STATUS_VIEWED,
                'viewed_at' => now(),
            ]);

            $invoice->activities()->create([
                'type' => 'viewed',
                'description' => 'Invoice viewed by recipient',
            ]);
        }
    }

    /**
     * Cancel an invoice and unmark time entries as billed.
     */
    public function cancel(Invoice $invoice, ?string $reason = null): void
    {
        DB::transaction(function () use ($invoice, $reason) {
            // Get time entry IDs from lines before canceling
            $timeEntryIds = $invoice->lines()
                ->whereNotNull('time_entry_id')
                ->pluck('time_entry_id');

            // Unmark time entries
            if ($timeEntryIds->isNotEmpty()) {
                TimeEntry::whereIn('id', $timeEntryIds)
                    ->update(['is_billed' => false]);
            }

            // Cancel any pending reminders
            $invoice->reminders()
                ->where('status', InvoiceReminder::STATUS_PENDING)
                ->update(['status' => InvoiceReminder::STATUS_CANCELLED]);

            // Update invoice status
            $invoice->update([
                'status' => Invoice::STATUS_CANCELLED,
                'internal_notes' => $reason
                    ? ($invoice->internal_notes ? $invoice->internal_notes."\n\n" : '')."Cancelled: {$reason}"
                    : $invoice->internal_notes,
            ]);

            $invoice->activities()->create([
                'type' => 'cancelled',
                'description' => $reason ? "Invoice cancelled: {$reason}" : 'Invoice cancelled',
            ]);
        });
    }

    /**
     * Clone an invoice (for recurring billing or re-sending).
     */
    public function clone(Invoice $invoice): Invoice
    {
        return DB::transaction(function () use ($invoice) {
            $newInvoice = Invoice::createWithUniqueNumber([
                'client_id' => $invoice->client_id,
                'project_id' => $invoice->project_id,
                'subject' => $invoice->subject,
                'notes' => $invoice->notes,
                'status' => Invoice::STATUS_DRAFT,
                'subtotal' => 0,
                'tax_rate' => $invoice->tax_rate,
                'tax_amount' => 0,
                'total' => 0,
                'amount_paid' => 0,
                'amount_due' => 0,
                'issue_date' => now(),
                'due_date' => $this->calculateDueDate($invoice->client),
                'payment_terms' => $invoice->payment_terms,
                'currency' => $invoice->currency,
            ]);

            // Clone lines (except time entry links - those shouldn't be re-billed)
            foreach ($invoice->lines as $line) {
                $newInvoice->lines()->create([
                    'type' => $line->type,
                    'description' => $line->description,
                    'details' => $line->details,
                    'quantity' => $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => $line->unit_price,
                    'taxable' => $line->taxable,
                    'project_id' => $line->project_id,
                    'sort_order' => $line->sort_order,
                    // Note: time_entry_id not copied - prevents double-billing
                ]);
            }

            return $newInvoice;
        });
    }

    /**
     * Build line items from time entries based on grouping mode.
     */
    protected function buildLineItems(Collection $timeEntries, string $groupMode): array
    {
        return match ($groupMode) {
            self::GROUP_BY_PROJECT => $this->buildProjectGroupedLines($timeEntries),
            self::GROUP_BY_TASK => $this->buildTaskGroupedLines($timeEntries),
            self::GROUP_BY_DATE => $this->buildDateGroupedLines($timeEntries),
            default => $this->buildIndividualLines($timeEntries),
        };
    }

    /**
     * Each time entry becomes its own line item.
     */
    protected function buildIndividualLines(Collection $timeEntries): array
    {
        return $timeEntries->map(function (TimeEntry $entry) {
            $description = $entry->notes ?: 'Professional services';

            if ($entry->task) {
                $description = $entry->task->title.($entry->notes ? ": {$entry->notes}" : '');
            }

            return [
                'description' => $description,
                'details' => $entry->spent_date->format('M j, Y'),
                'quantity' => $entry->hours,
                'unit_price' => $entry->hourly_rate,
                'amount' => $entry->hours * $entry->hourly_rate,
                'time_entry_id' => $entry->id,
                'project_id' => $entry->project_id,
                'service_date' => $entry->spent_date,
            ];
        })->all();
    }

    /**
     * Aggregate entries by project.
     */
    protected function buildProjectGroupedLines(Collection $timeEntries): array
    {
        $grouped = $timeEntries->groupBy('project_id');
        $lines = [];

        foreach ($grouped as $projectId => $entries) {
            $project = $entries->first()->project;
            $totalHours = $entries->sum('hours');
            $avgRate = $entries->avg('hourly_rate');

            // Check if all entries have same rate
            $rates = $entries->pluck('hourly_rate')->unique();
            $rate = $rates->count() === 1 ? $rates->first() : $avgRate;

            $dateRange = $this->formatDateRange($entries);
            $projectName = $project?->name ?? 'General';

            $lines[] = [
                'description' => "{$projectName} - Professional services",
                'details' => $dateRange,
                'quantity' => round($totalHours, 2),
                'unit_price' => $rate,
                'amount' => round($totalHours * $rate, 2),
                'project_id' => $projectId,
            ];
        }

        return $lines;
    }

    /**
     * Aggregate entries by task.
     */
    protected function buildTaskGroupedLines(Collection $timeEntries): array
    {
        $grouped = $timeEntries->groupBy('task_id');
        $lines = [];

        foreach ($grouped as $taskId => $entries) {
            $task = $entries->first()->task;
            $totalHours = $entries->sum('hours');
            $avgRate = $entries->avg('hourly_rate');

            $rates = $entries->pluck('hourly_rate')->unique();
            $rate = $rates->count() === 1 ? $rates->first() : $avgRate;

            $dateRange = $this->formatDateRange($entries);
            $taskTitle = $task?->title ?? 'General work';

            $lines[] = [
                'description' => $taskTitle,
                'details' => $dateRange,
                'quantity' => round($totalHours, 2),
                'unit_price' => $rate,
                'amount' => round($totalHours * $rate, 2),
                'project_id' => $entries->first()->project_id,
            ];
        }

        return $lines;
    }

    /**
     * Aggregate entries by date.
     */
    protected function buildDateGroupedLines(Collection $timeEntries): array
    {
        $grouped = $timeEntries->groupBy(fn ($e) => $e->spent_date->format('Y-m-d'));
        $lines = [];

        foreach ($grouped as $date => $entries) {
            $totalHours = $entries->sum('hours');
            $avgRate = $entries->avg('hourly_rate');

            $rates = $entries->pluck('hourly_rate')->unique();
            $rate = $rates->count() === 1 ? $rates->first() : $avgRate;

            $formattedDate = Carbon::parse($date)->format('M j, Y');
            $notes = $entries->pluck('notes')->filter()->unique()->implode('; ');

            $lines[] = [
                'description' => $notes ?: 'Professional services',
                'details' => $formattedDate,
                'quantity' => round($totalHours, 2),
                'unit_price' => $rate,
                'amount' => round($totalHours * $rate, 2),
                'service_date' => Carbon::parse($date),
            ];
        }

        return $lines;
    }

    /**
     * Format a date range from a collection of entries.
     */
    protected function formatDateRange(Collection $entries): string
    {
        $dates = $entries->pluck('spent_date')->sort();
        $first = $dates->first();
        $last = $dates->last();

        if ($first->eq($last)) {
            return $first->format('M j, Y');
        }

        if ($first->isSameMonth($last)) {
            return $first->format('M j').'-'.$last->format('j, Y');
        }

        return $first->format('M j').' - '.$last->format('M j, Y');
    }

    /**
     * Calculate due date based on client payment terms.
     */
    public function calculateDueDate(Client $client): Carbon
    {
        $terms = $client->payment_terms ?? 'Net 30';

        $days = match (true) {
            str_contains($terms, 'Receipt') => 0,
            str_contains($terms, '15') => 15,
            str_contains($terms, '30') => 30,
            str_contains($terms, '45') => 45,
            str_contains($terms, '60') => 60,
            str_contains($terms, '90') => 90,
            default => 30,
        };

        return now()->addDays($days);
    }

    /**
     * Derive payment terms label from the due date relative to today.
     */
    public static function derivePaymentTerms(Carbon $dueDate, ?string $clientDefault = null): string
    {
        $days = (int) now()->startOfDay()->diffInDays($dueDate->startOfDay(), false);

        if ($days <= 0) {
            return 'Due on Receipt';
        }

        $standard = [7, 10, 14, 15, 21, 30, 45, 60, 90];
        $closest = collect($standard)->sortBy(fn ($s) => abs($s - $days))->first();

        if (abs($closest - $days) <= 2) {
            return "Net {$closest}";
        }

        return "Net {$days}";
    }
}
