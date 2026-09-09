<?php

namespace App\Agents\Tools;

use App\Models\Contractor;
use App\Models\ContractorInvoice;

class ContractorGetPendingPaymentsTool extends BaseTool
{
    public function category(): string
    {
        return 'wise';
    }

    public function name(): string
    {
        return 'Get Pending Contractor Payments';
    }

    public function description(): string
    {
        return 'List contractors and invoices that are due for payment. Includes approved invoices and recurring payments due.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_recurring' => [
                    'type' => 'boolean',
                    'description' => 'Include recurring payments due this period. Default: true.',
                    'default' => true,
                ],
                'include_invoices' => [
                    'type' => 'boolean',
                    'description' => 'Include approved invoices awaiting payment. Default: true.',
                    'default' => true,
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        $includeRecurring = $params['include_recurring'] ?? true;
        $includeInvoices = $params['include_invoices'] ?? true;

        $pendingPayments = [];
        $totalAmount = 0;

        // Get approved invoices awaiting payment
        if ($includeInvoices) {
            $invoices = ContractorInvoice::with('contractor')
                ->where('status', ContractorInvoice::STATUS_APPROVED)
                ->orderBy('due_date')
                ->get();

            foreach ($invoices as $invoice) {
                if (! $invoice->contractor->canReceivePayments()) {
                    continue;
                }

                $pendingPayments[] = [
                    'type' => 'invoice',
                    'contractor_id' => $invoice->contractor_id,
                    'contractor_name' => $invoice->contractor->display_name,
                    'invoice_id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => $invoice->amount,
                    'currency' => $invoice->currency,
                    'due_date' => $invoice->due_date?->format('Y-m-d'),
                    'is_overdue' => $invoice->isOverdue(),
                    'recipient_id' => $invoice->contractor->wise_recipient_id,
                ];

                $totalAmount += $invoice->amount;
            }
        }

        // Get recurring payments due
        if ($includeRecurring) {
            $recurringContractors = Contractor::active()
                ->recurring()
                ->onboardingComplete()
                ->whereNotNull('wise_recipient_id')
                ->get();

            foreach ($recurringContractors as $contractor) {
                // Check if this is a payment period (simplified: monthly = any time in month)
                // In production, would check last payment date
                $isDue = $this->isRecurringPaymentDue($contractor);

                if ($isDue) {
                    $pendingPayments[] = [
                        'type' => 'recurring',
                        'contractor_id' => $contractor->id,
                        'contractor_name' => $contractor->display_name,
                        'amount' => $contractor->recurring_amount,
                        'currency' => $contractor->recurring_currency,
                        'schedule' => $contractor->recurring_schedule,
                        'recipient_id' => $contractor->wise_recipient_id,
                    ];

                    $totalAmount += $contractor->recurring_amount;
                }
            }
        }

        // Sort by overdue first, then by due date
        usort($pendingPayments, function ($a, $b) {
            if (($a['is_overdue'] ?? false) !== ($b['is_overdue'] ?? false)) {
                return ($a['is_overdue'] ?? false) ? -1 : 1;
            }

            return ($a['due_date'] ?? '9999') <=> ($b['due_date'] ?? '9999');
        });

        return [
            'success' => true,
            'count' => count($pendingPayments),
            'total_amount_usd' => $totalAmount, // Simplified: assumes USD
            'pending_payments' => $pendingPayments,
            'summary' => [
                'invoices' => count(array_filter($pendingPayments, fn ($p) => $p['type'] === 'invoice')),
                'recurring' => count(array_filter($pendingPayments, fn ($p) => $p['type'] === 'recurring')),
                'overdue' => count(array_filter($pendingPayments, fn ($p) => $p['is_overdue'] ?? false)),
            ],
        ];
    }

    protected function isRecurringPaymentDue(Contractor $contractor): bool
    {
        // Get last payment to this contractor
        $lastPayment = $contractor->transfers()
            ->where('payment_type', 'recurring')
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->first();

        if (! $lastPayment) {
            // Never paid - due now
            return true;
        }

        $lastPaymentDate = $lastPayment->created_at;

        return match ($contractor->recurring_schedule) {
            'weekly' => $lastPaymentDate->diffInWeeks(now()) >= 1,
            'biweekly' => $lastPaymentDate->diffInWeeks(now()) >= 2,
            'monthly' => $lastPaymentDate->diffInMonths(now()) >= 1,
            default => false,
        };
    }
}
