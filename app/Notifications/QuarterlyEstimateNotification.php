<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuarterlyEstimateNotification extends Notification
{
    use Queueable;

    public function __construct(
        public int $quarter,
        public int $year,
        public float $newEstimate,
        public float $previousEstimate,
        public float $projectedIncome,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $change = $this->newEstimate - $this->previousEstimate;
        $direction = $change > 0 ? 'increased' : 'decreased';
        $changeAmt = '$'.number_format(abs($change), 0);

        return (new MailMessage)
            ->subject("Q{$this->quarter} {$this->year} tax estimate updated — \$".number_format($this->newEstimate, 0))
            ->greeting('Tax Estimate Update')
            ->line("Your Q{$this->quarter} estimated tax payment has {$direction} by {$changeAmt}.")
            ->line('Based on your projected annual income of $'.number_format($this->projectedIncome, 0).", your Q{$this->quarter} federal payment should be \$".number_format($this->newEstimate, 0).'.')
            ->line('Download your updated 1040-ES payment voucher from the Tax Office.')
            ->action('Open Tax Office', url('/life/tax-optimizer'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'quarter' => $this->quarter,
            'year' => $this->year,
            'new_estimate' => $this->newEstimate,
            'previous_estimate' => $this->previousEstimate,
            'projected_income' => $this->projectedIncome,
        ];
    }
}
