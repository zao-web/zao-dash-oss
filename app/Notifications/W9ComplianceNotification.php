<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class W9ComplianceNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $contractorName,
        public float $ytdPayments,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("W-9 needed: {$this->contractorName}")
            ->greeting('W-9 Compliance Alert')
            ->line("{$this->contractorName} has been paid \$".number_format($this->ytdPayments, 0).' this year.')
            ->line('Since payments exceed $600, you need a W-9 on file before you can issue their 1099-NEC at year end.')
            ->line('Without a W-9, you may need to withhold 24% backup withholding on future payments.')
            ->action('Request W-9 from Tax Office', url('/life/tax-optimizer'))
            ->line('The system will help you generate a W-9 request email.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'contractor_name' => $this->contractorName,
            'ytd_payments' => $this->ytdPayments,
        ];
    }
}
