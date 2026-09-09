<?php

namespace App\Jobs;

use App\Models\EstimatedTaxPayment;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\TaxCalendarEvent;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Weekly financial digest — plain English summary every Monday at 7am.
 */
class WeeklyFinancialDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $user = User::first();
        if (! $user) {
            return;
        }

        $year = now()->year;
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();

        // Revenue received this week
        $weeklyRevenue = Payment::whereBetween('payment_date', [$weekStart, $weekEnd])
            ->where('status', 'completed')
            ->sum('amount');

        // Upcoming obligations in next 14 days
        $upcomingDeadlines = TaxCalendarEvent::where('user_id', $user->id)
            ->where('due_date', '>=', now())
            ->where('due_date', '<=', now()->addDays(14))
            ->where('status', '!=', 'completed')
            ->orderBy('due_date')
            ->get();

        // YTD payments vs estimates
        $ytdPayments = EstimatedTaxPayment::ytdPaymentsByJurisdiction($user->id, $year);

        // Build the digest
        $lines = [];
        $lines[] = '**Weekly Financial Digest — '.now()->format('F j, Y').'**';
        $lines[] = '';

        // Revenue
        if ($weeklyRevenue > 0) {
            $taxReserve = round($weeklyRevenue * 0.30, 0);
            $lines[] = 'Revenue received this week: $'.number_format($weeklyRevenue, 0);
            $lines[] = 'Tax reserve (30%): $'.number_format($taxReserve, 0);
        } else {
            $lines[] = 'No revenue received this week.';
        }
        $lines[] = '';

        // Upcoming deadlines
        if ($upcomingDeadlines->isNotEmpty()) {
            $lines[] = '**Upcoming in the next 14 days:**';
            foreach ($upcomingDeadlines as $event) {
                $daysUntil = now()->diffInDays($event->due_date, false);
                $amount = $event->estimated_amount ? ' ($'.number_format($event->estimated_amount, 0).')' : '';
                $lines[] = "- {$event->form_type}{$amount} — due ".
                    $event->due_date->format('M j').
                    " ({$daysUntil} days)";
            }
        } else {
            $lines[] = 'No deadlines in the next 14 days.';
        }
        $lines[] = '';

        // Estimated payments status
        $lines[] = '**YTD Estimated Tax Payments:**';
        $lines[] = '- Federal: $'.number_format($ytdPayments['federal'], 0);
        $lines[] = '- Oregon: $'.number_format($ytdPayments['state_or'], 0);
        $lines[] = '- Total paid: $'.number_format($ytdPayments['total'], 0);

        // Open invoices
        $openInvoices = Invoice::whereIn('status', ['sent', 'viewed', 'overdue'])
            ->sum('amount_due');
        if ($openInvoices > 0) {
            $lines[] = '';
            $lines[] = 'Outstanding invoices: $'.number_format($openInvoices, 0);
        }

        $message = implode("\n", $lines);

        Notification::create([
            'user_id' => $user->id,
            'type' => 'system',
            'title' => 'Weekly Financial Digest',
            'message' => $message,
            'severity' => 'info',
            'action_url' => '/life/strategy',
            'action_label' => 'View Strategy',
        ]);

        Log::info('WeeklyFinancialDigestJob: digest generated', [
            'weekly_revenue' => $weeklyRevenue,
            'upcoming_deadlines' => $upcomingDeadlines->count(),
            'ytd_tax_payments' => $ytdPayments['total'],
        ]);
    }
}
