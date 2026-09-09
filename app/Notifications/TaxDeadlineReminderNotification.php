<?php

namespace App\Notifications;

use App\Models\TaxCalendarEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TaxDeadlineReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        public TaxCalendarEvent $event,
        public int $daysUntilDue,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $form = $this->event->form_type ?? 'Tax Filing';
        $jurisdiction = $this->event->filing_jurisdiction === 'federal' ? 'Federal' : 'Oregon';
        $dueDate = $this->event->due_date->format('F j, Y');
        $amount = $this->event->estimated_amount
            ? '$'.number_format($this->event->estimated_amount, 0)
            : '';

        $urgency = $this->daysUntilDue <= 3 ? 'URGENT: ' : '';

        return (new MailMessage)
            ->subject("{$urgency}{$jurisdiction} {$form} due in {$this->daysUntilDue} days")
            ->greeting('Tax Deadline Reminder')
            ->line("Your {$jurisdiction} {$form} is due {$dueDate} — {$this->daysUntilDue} days from now.")
            ->when($amount, fn ($mail) => $mail->line("Estimated amount: {$amount}"))
            ->line('You can download your pre-filled payment voucher from the Tax Office.')
            ->action('Open Tax Office', url('/life/tax-optimizer'))
            ->line('This reminder was generated automatically by your Zao Dash Tax Office.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'event_id' => $this->event->id,
            'form_type' => $this->event->form_type,
            'due_date' => $this->event->due_date->toDateString(),
            'days_until_due' => $this->daysUntilDue,
            'estimated_amount' => $this->event->estimated_amount,
        ];
    }
}
