<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentTaxReservationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $clientName,
        public float $paymentAmount,
        public float $taxReserve,
        public float $taxRate,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ratePct = round($this->taxRate * 100);

        return (new MailMessage)
            ->subject('Payment received — $'.number_format($this->taxReserve, 0).' reserved for taxes')
            ->greeting('Payment Received')
            ->line('You received $'.number_format($this->paymentAmount, 0)." from {$this->clientName}.")
            ->line('Setting aside $'.number_format($this->taxReserve, 0)." ({$ratePct}%) for estimated taxes.")
            ->line("This helps ensure you don't get caught short at tax time.")
            ->action('View Tax Office', url('/life/tax-optimizer'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'client_name' => $this->clientName,
            'payment_amount' => $this->paymentAmount,
            'tax_reserve' => $this->taxReserve,
            'tax_rate' => $this->taxRate,
        ];
    }
}
