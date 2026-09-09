<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Models\TaxCalendarEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends tax deadline reminders at 21, 14, 7, and 3 days before due dates.
 * Runs daily at 7am.
 */
class SendTaxDeadlineRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $reminderDays = [21, 14, 7, 3];
        $sent = 0;

        foreach ($reminderDays as $days) {
            $targetDate = now()->addDays($days)->toDateString();

            $events = TaxCalendarEvent::where('due_date', $targetDate)
                ->where('status', '!=', 'completed')
                ->get();

            foreach ($events as $event) {
                $severity = $days <= 3 ? 'error' : ($days <= 7 ? 'warning' : 'info');

                $formLabel = $event->form_type ?? 'Tax Filing';
                $jurisdiction = $event->filing_jurisdiction === 'federal' ? 'Federal' : 'Oregon';
                $amount = $event->estimated_amount ? ' — $'.number_format($event->estimated_amount, 0) : '';

                Notification::create([
                    'user_id' => $event->user_id,
                    'type' => 'system',
                    'title' => "{$jurisdiction} {$formLabel} due in {$days} days{$amount}",
                    'message' => $this->buildMessage($event, $days),
                    'severity' => $severity,
                    'action_url' => '/life/tax-optimizer',
                    'action_label' => 'View Tax Office',
                    'metadata' => [
                        'tax_calendar_event_id' => $event->id,
                        'days_until_due' => $days,
                        'form_type' => $event->form_type,
                    ],
                ]);

                $sent++;

                Log::info("TaxDeadlineReminder: {$days} days notice", [
                    'event_id' => $event->id,
                    'form' => $event->form_type,
                    'due_date' => $event->due_date->format('M j, Y'),
                ]);
            }
        }

        if ($sent > 0) {
            Log::info("SendTaxDeadlineRemindersJob: sent {$sent} reminders");
        }
    }

    protected function buildMessage(TaxCalendarEvent $event, int $days): string
    {
        $dueDate = $event->due_date->format('F j, Y');
        $formType = $event->form_type ?? 'filing';

        if ($event->event_type === 'quarterly_estimate') {
            $quarter = $event->quarter;
            $jurisdiction = $event->filing_jurisdiction === 'federal' ? 'federal' : 'Oregon';

            return "Your Q{$quarter} {$jurisdiction} estimated tax payment ({$formType}) is due {$dueDate} — {$days} days from now. ".
                ($event->estimated_amount
                    ? 'Estimated amount: $'.number_format($event->estimated_amount, 0).'. '
                    : '').
                'Download your pre-filled payment voucher from the Tax Office.';
        }

        if ($event->event_type === '1099_filing') {
            return "1099-NEC forms must be filed and sent to contractors by {$dueDate} — {$days} days from now. ".
                'Download all 1099s from the Tax Office.';
        }

        if ($event->event_type === 'annual_return') {
            return "Your {$formType} return is due {$dueDate} — {$days} days from now. ".
                'Review your draft return in the Tax Office.';
        }

        return "Tax deadline: {$formType} is due {$dueDate} — {$days} days from now.";
    }
}
