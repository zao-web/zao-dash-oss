<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceActivity;
use App\Models\InvoiceLine;
use App\Models\RetainerPeriod;
use App\Models\User;
use App\Services\Invoicing\InvoiceService;
use App\Services\PayPal\PayPalService;
use App\Services\Reports\RetainerHealthService;
use App\Services\Slack\SlackApiService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GenerateRecurringInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(InvoiceService $invoiceService, PayPalService $paypalService): void
    {
        $today = now();
        $dayOfMonth = $today->day;

        // Find clients with recurring invoices enabled where today is their invoice day
        $clients = Client::where('recurring_invoice_enabled', true)
            ->where('recurring_invoice_amount', '>', 0)
            ->where('recurring_invoice_day', $dayOfMonth)
            ->where('status', 'active')
            ->get();

        foreach ($clients as $client) {
            $this->generateInvoiceForClient($client, $today, $invoiceService, $paypalService);
        }

        // Also handle clients with invoice day > 28 on the last day of short months
        if ($dayOfMonth === $today->daysInMonth && $dayOfMonth < 28) {
            $lateClients = Client::where('recurring_invoice_enabled', true)
                ->where('recurring_invoice_amount', '>', 0)
                ->where('recurring_invoice_day', '>', $dayOfMonth)
                ->where('status', 'active')
                ->get();

            foreach ($lateClients as $client) {
                $this->generateInvoiceForClient($client, $today, $invoiceService, $paypalService);
            }
        }

        Log::info('Recurring invoices job completed', [
            'date' => $today->toDateString(),
            'clients_processed' => $clients->count(),
        ]);
    }

    protected function generateInvoiceForClient(
        Client $client,
        Carbon $today,
        InvoiceService $invoiceService,
        PayPalService $paypalService
    ): void {
        // The month the invoice is labelled for. Clients billed in advance (e.g.
        // Windham, invoiced on the 21st to be paid near the 1st) get the *next*
        // month so the subject reads "July 2026" when issued in late June.
        $periodStart = $client->recurring_invoice_in_advance
            ? $today->copy()->addMonthNoOverflow()->startOfMonth()
            : $today->copy()->startOfMonth();

        // Skip if already generated this month
        if ($client->recurring_invoice_last_generated &&
            Carbon::parse($client->recurring_invoice_last_generated)->isSameMonth($today)) {
            return;
        }

        // If auto-send is on, require a recipient *before* creating the invoice.
        // Without one, PayPal createInvoice fails and the draft gets stuck.
        if ($client->recurring_invoice_auto_send && ! $this->resolveRecipientEmail($client)) {
            Log::warning('Skipping recurring invoice: client has auto-send on but no recipient', [
                'client_id' => $client->id,
                'client_name' => $client->name,
            ]);

            $this->notifyOwnerOfMissingRecipient($client);

            // Intentionally do NOT update recurring_invoice_last_generated — fix the
            // contact data and the next daily run of the job will pick it up.
            return;
        }

        try {
            DB::transaction(function () use ($client, $today, $periodStart, $invoiceService, $paypalService) {
                $retainerPeriod = $this->findRetainerPeriodForIssueDate($client, $today);

                // Create the invoice with retry for unique constraint violations
                $invoice = Invoice::createWithUniqueNumber([
                    'client_id' => $client->id,
                    'project_id' => $client->recurring_invoice_project_id,
                    'retainer_period_id' => $retainerPeriod?->id,
                    'subject' => $this->processMergeTags(
                        $client->recurring_invoice_description ?? 'Monthly Retainer - {month_year}',
                        $client,
                        $periodStart,
                    ),
                    'notes' => $client->invoice_notes,
                    'status' => Invoice::STATUS_DRAFT,
                    'is_recurring' => true,
                    'subtotal' => $client->recurring_invoice_amount,
                    'tax_rate' => $client->default_tax_rate ?? 0,
                    'tax_amount' => $this->calculateTax($client->recurring_invoice_amount, $client->default_tax_rate),
                    'total' => $this->calculateTotal($client->recurring_invoice_amount, $client->default_tax_rate),
                    'amount_paid' => 0,
                    'amount_due' => $this->calculateTotal($client->recurring_invoice_amount, $client->default_tax_rate),
                    'issue_date' => $today,
                    'due_date' => $invoiceService->calculateDueDate($client),
                    'payment_terms' => $client->payment_terms ?? 'Net 30',
                    'currency' => 'USD',
                ]);

                // Add the line item
                InvoiceLine::create([
                    'invoice_id' => $invoice->id,
                    'type' => 'fixed',
                    'description' => $this->processMergeTags(
                        $client->recurring_invoice_description ?? 'Monthly Retainer - {month_year}',
                        $client,
                        $periodStart,
                    ),
                    'details' => null,
                    'quantity' => 1,
                    'unit' => null,
                    'unit_price' => $client->recurring_invoice_amount,
                    'amount' => $client->recurring_invoice_amount,
                    'sort_order' => 0,
                ]);

                // Update last generated date
                $client->update(['recurring_invoice_last_generated' => $today]);

                Log::info('Recurring invoice generated', [
                    'client_id' => $client->id,
                    'client_name' => $client->name,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->number,
                    'amount' => $invoice->total,
                ]);

                // Hold for review if the retainer hours fell short of the threshold.
                $shortfall = $retainerPeriod
                    ? $this->evaluateRetainerShortfall($invoice, $retainerPeriod)
                    : null;

                if ($shortfall) {
                    $invoice->update([
                        'pending_review' => true,
                        'pending_review_reason' => $shortfall['reason'],
                    ]);

                    InvoiceActivity::create([
                        'invoice_id' => $invoice->id,
                        'user_id' => null,
                        'type' => 'held_for_review',
                        'description' => $shortfall['reason'],
                        'metadata' => $shortfall['metadata'],
                    ]);

                    $this->notifyOwnerOfShortfall($invoice, $shortfall);

                    return;
                }

                // Auto-send if configured (only when not held for review)
                if ($client->recurring_invoice_auto_send) {
                    $this->sendInvoice($invoice, $invoiceService, $paypalService);
                }
            });
        } catch (\Exception $e) {
            Log::error('Failed to generate recurring invoice', [
                'client_id' => $client->id,
                'client_name' => $client->name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function processMergeTags(string $template, Client $client, Carbon $periodStart): string
    {
        $project = $client->recurring_invoice_project_id
            ? $client->projects()->find($client->recurring_invoice_project_id)
            : null;

        return str_replace(
            ['{month}', '{year}', '{month_year}', '{client_name}', '{project_name}'],
            [
                $periodStart->format('F'),
                $periodStart->format('Y'),
                $periodStart->format('F Y'),
                $client->name,
                $project?->name ?? '',
            ],
            $template,
        );
    }

    protected function calculateTax(float $amount, ?float $taxRate): float
    {
        if (! $taxRate || $taxRate <= 0) {
            return 0;
        }

        return round($amount * ($taxRate / 100), 2);
    }

    protected function calculateTotal(float $amount, ?float $taxRate): float
    {
        return round($amount + $this->calculateTax($amount, $taxRate), 2);
    }

    protected function sendInvoice(Invoice $invoice, InvoiceService $invoiceService, PayPalService $paypalService): void
    {
        try {
            // Create PayPal invoice if not exists
            if (! $invoice->paypal_invoice_id) {
                $paypalService->createInvoice($invoice);
                $invoice->refresh();
            }

            // Mark as sent
            $invoiceService->markAsSent($invoice);

            // Get payment link
            $paymentLink = $invoice->paypal_invoice_id
                ? $paypalService->getPaymentLink($invoice)
                : null;

            // Send email — CC any additional billing addresses on the client.
            $recipientEmail = $this->resolveRecipientEmail($invoice->client);

            if ($recipientEmail) {
                Mail::to($recipientEmail)
                    ->cc($invoice->client->billingCcList())
                    ->send(new \App\Mail\InvoiceSentMail($invoice, $paymentLink));
            }

            Log::info('Recurring invoice auto-sent', [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->number,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to auto-send recurring invoice', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);

            $this->recordSendFailure($invoice, $e);
        }
    }

    protected function resolveRecipientEmail(Client $client): ?string
    {
        if (! empty($client->billing_email)) {
            return $client->billing_email;
        }

        return $client->contacts()->whereNotNull('email')->first()?->email;
    }

    protected function recordSendFailure(Invoice $invoice, \Throwable $e): void
    {
        try {
            InvoiceActivity::create([
                'invoice_id' => $invoice->id,
                'user_id' => null,
                'type' => 'auto_send_failed',
                'description' => "Auto-send failed: {$e->getMessage()}",
                'metadata' => [
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ],
            ]);
        } catch (\Throwable $logException) {
            Log::error('Failed to record auto_send_failed activity', [
                'invoice_id' => $invoice->id,
                'error' => $logException->getMessage(),
            ]);
        }

        $this->notifyOwnerOfSendFailure($invoice, $e);
    }

    protected function notifyOwnerOfMissingRecipient(Client $client): void
    {
        $text = sprintf(
            ':warning: Skipped recurring invoice for *%s* — client has auto-send enabled but no `billing_email` or contact email. Add a contact (or set billing_email), and the next run will generate the invoice.',
            $client->name,
        );

        $this->sendOwnerSlackDm($text);
    }

    protected function notifyOwnerOfSendFailure(Invoice $invoice, \Throwable $e): void
    {
        $text = sprintf(
            ':rotating_light: Auto-send failed for invoice *#%s* (%s) — invoice stayed in draft. Error: `%s`',
            $invoice->number,
            $invoice->client?->name ?? "client #{$invoice->client_id}",
            $e->getMessage(),
        );

        $this->sendOwnerSlackDm($text);
    }

    protected function sendOwnerSlackDm(string $text): void
    {
        try {
            $owner = User::query()->where('role', 'owner')->first();
            $slackUserId = $owner?->slack_user_id ?? config('services.slack.owner_user_id');

            if (! $slackUserId) {
                return;
            }

            app(SlackApiService::class)->postMessageDirect($slackUserId, $text);
        } catch (\Throwable $e) {
            Log::warning('Failed to Slack-notify owner about recurring invoice issue', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find the retainer period being billed by an invoice issued today.
     *
     * A recurring invoice issued at the start of the month bills the *prior*
     * period. We pick the most recent period whose period_end is on or before
     * the issue_date.
     */
    protected function findRetainerPeriodForIssueDate(Client $client, Carbon $issueDate): ?RetainerPeriod
    {
        return RetainerPeriod::query()
            ->where('client_id', $client->id)
            ->where('period_end', '<=', $issueDate->toDateString())
            ->orderByDesc('period_end')
            ->first();
    }

    /**
     * Determine whether a retainer period fell short of the hours threshold.
     * Returns null when no shortfall applies (no hours budget, hit threshold,
     * or no data flowing yet — caller decides what to do with that).
     *
     * @return array{
     *   reason: string,
     *   metadata: array<string, mixed>,
     * }|null
     */
    protected function evaluateRetainerShortfall(Invoice $invoice, RetainerPeriod $period): ?array
    {
        $health = app(RetainerHealthService::class);

        $snapshot = $health->computeAndPersistSnapshot(
            $period,
            Carbon::parse($period->period_start),
            Carbon::parse($period->period_end),
            persist: false,
        );

        $hoursIncluded = (float) $period->hours_included;
        if ($hoursIncluded <= 0) {
            return null;
        }

        $totalEquivHours = (float) $snapshot['total_equivalent_hours'];
        $usageRatio = $totalEquivHours / $hoursIncluded;

        if ($usageRatio >= 0.80) {
            return null;
        }

        // Build 3-month context by snapshotting prior periods read-only.
        $priorPeriods = RetainerPeriod::query()
            ->where('client_id', $period->client_id)
            ->where('id', '!=', $period->id)
            ->where('period_end', '<', $period->period_start)
            ->orderByDesc('period_end')
            ->limit(3)
            ->get();

        $priorContext = $priorPeriods->map(function (RetainerPeriod $p) use ($health) {
            $snap = $health->computeAndPersistSnapshot(
                $p,
                Carbon::parse($p->period_start),
                Carbon::parse($p->period_end),
                persist: false,
            );

            return [
                'period_start' => Carbon::parse($p->period_start)->toDateString(),
                'period_end' => Carbon::parse($p->period_end)->toDateString(),
                'hours_included' => (float) $p->hours_included,
                'total_equivalent_hours' => (float) $snap['total_equivalent_hours'],
                'usage_percent' => $snap['usage_percent'],
            ];
        })->all();

        $reason = sprintf(
            'Low hours this period: %.1f / %.1f hrs (%.0f%% of retainer). Held for review before sending.',
            $totalEquivHours,
            $hoursIncluded,
            $usageRatio * 100,
        );

        return [
            'reason' => $reason,
            'metadata' => [
                'period_id' => $period->id,
                'period_start' => Carbon::parse($period->period_start)->toDateString(),
                'period_end' => Carbon::parse($period->period_end)->toDateString(),
                'hours_included' => $hoursIncluded,
                'total_equivalent_hours' => $totalEquivHours,
                'human_hours' => $snapshot['human_hours'],
                'human_hours_source' => $snapshot['human_hours_source'] ?? 'tracked',
                'meeting_hours' => $snapshot['meeting_hours'],
                'agent_equivalent_hours' => $snapshot['agent_equivalent_hours'],
                'usage_percent' => $snapshot['usage_percent'],
                'period_activity' => $snapshot['period_activity'] ?? null,
                'prior_periods' => $priorContext,
            ],
        ];
    }

    /**
     * Slack-DM the owner about a retainer shortfall with prior-month context.
     *
     * @param  array{reason: string, metadata: array<string, mixed>}  $shortfall
     */
    protected function notifyOwnerOfShortfall(Invoice $invoice, array $shortfall): void
    {
        $client = $invoice->client;
        $meta = $shortfall['metadata'];

        $lines = [
            sprintf(
                ':hourglass_flowing_sand: Recurring invoice *#%s* for *%s* held for review — low hours this period.',
                $invoice->number,
                $client?->name ?? "client #{$invoice->client_id}",
            ),
            sprintf(
                '> *This period* (%s → %s): %.1f / %.1f hrs (%.0f%%)',
                $meta['period_start'],
                $meta['period_end'],
                $meta['total_equivalent_hours'],
                $meta['hours_included'],
                $meta['usage_percent'],
            ),
            sprintf(
                '> Breakdown — human: %.1f hrs%s · meetings: %.1f hrs · agent-equiv: %.1f hrs',
                $meta['human_hours'],
                ($meta['human_hours_source'] ?? 'tracked') === 'estimated' ? ' (estimated)' : '',
                $meta['meeting_hours'],
                $meta['agent_equivalent_hours'],
            ),
        ];

        $activity = $meta['period_activity'] ?? null;
        if ($activity) {
            $gh = $activity['github'] ?? [];
            $slack = $activity['slack'] ?? [];

            $activityLine = sprintf(
                '> Activity — commits: %d · PRs merged: %d · issues closed: %d · Slack: %d total (%d from client)',
                $gh['commits'] ?? 0,
                $gh['prs_merged'] ?? 0,
                $gh['issues_closed'] ?? 0,
                $slack['total_messages'] ?? 0,
                $slack['external_messages'] ?? 0,
            );

            $hasActivity = ($gh['commits'] ?? 0) > 0
                || ($gh['prs_merged'] ?? 0) > 0
                || ($gh['issues_closed'] ?? 0) > 0
                || ($slack['total_messages'] ?? 0) > 0;

            if ($hasActivity) {
                $activityLine .= ' — work happened but no hours were logged against the retainer.';
            }

            $lines[] = $activityLine;
        }

        if (! empty($meta['prior_periods'])) {
            $lines[] = '> *Prior periods:*';
            foreach ($meta['prior_periods'] as $p) {
                $lines[] = sprintf(
                    '> • %s → %s: %.1f / %.1f hrs (%.0f%%)',
                    $p['period_start'],
                    $p['period_end'],
                    $p['total_equivalent_hours'],
                    $p['hours_included'],
                    $p['usage_percent'],
                );
            }
        }

        $lines[] = sprintf(
            '> Review and send: %s/invoices/%d',
            rtrim(config('app.url'), '/'),
            $invoice->id,
        );

        $this->sendOwnerSlackDm(implode("\n", $lines));
    }
}
