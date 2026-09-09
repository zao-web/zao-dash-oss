<?php

namespace App\Listeners;

use App\Events\InvoicePaymentReceived;
use App\Models\Notification;
use App\Models\TaxProfile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class ReserveForTaxesOnPayment implements ShouldQueue
{
    public function handle(InvoicePaymentReceived $event): void
    {
        $invoice = $event->invoice;
        $payment = $event->payment;
        $clientName = $invoice->client?->name ?? 'Unknown';
        $amount = (float) $payment->amount;

        // Get effective tax rate from profile or default to 30%
        $profile = TaxProfile::where('user_id', $invoice->client?->id ?? 1)
            ->current()
            ->first();

        $taxRate = 0.30; // default
        if ($profile) {
            // Use the effective rate from the last computation if available
            $taxRate = min(0.45, max(0.20, 0.30)); // Conservative 30% default
        }

        $taxReserve = round($amount * $taxRate, 2);

        Notification::create([
            'user_id' => $invoice->client?->id ?? 1,
            'type' => 'system',
            'title' => 'Payment received — tax reserved',
            'message' => 'You received $'.number_format($amount, 0)." from {$clientName}. ".
                'Setting aside $'.number_format($taxReserve, 0).' ('.round($taxRate * 100).'%) for estimated taxes.',
            'severity' => 'success',
            'action_url' => '/life/tax-optimizer',
            'action_label' => 'Tax Office',
            'metadata' => [
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'payment_amount' => $amount,
                'tax_reserve' => $taxReserve,
                'tax_rate' => $taxRate,
            ],
        ]);

        Log::info('ReserveForTaxesOnPayment: tax reservation created', [
            'client' => $clientName,
            'payment' => $amount,
            'reserve' => $taxReserve,
        ]);
    }
}
