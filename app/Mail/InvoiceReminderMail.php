<?php

namespace App\Mail;

use App\Mail\Concerns\CcsInternalStakeholders;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class InvoiceReminderMail extends Mailable
{
    use CcsInternalStakeholders, Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public InvoiceReminder $reminder,
        public ?string $paymentLink = null
    ) {}

    public function envelope(): Envelope
    {
        $subject = match ($this->reminder->type) {
            InvoiceReminder::TYPE_BEFORE_DUE => sprintf(
                'Reminder: Invoice #%s due in %d days',
                $this->invoice->number,
                abs($this->reminder->days_offset)
            ),
            InvoiceReminder::TYPE_ON_DUE => sprintf(
                'Payment Due Today: Invoice #%s',
                $this->invoice->number
            ),
            InvoiceReminder::TYPE_OVERDUE => sprintf(
                'Past Due: Invoice #%s (%d days overdue)',
                $this->invoice->number,
                $this->invoice->days_overdue
            ),
            default => sprintf(
                'Invoice #%s Reminder',
                $this->invoice->number
            ),
        };

        return new Envelope(subject: $subject, cc: $this->internalCcs());
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoice-reminder',
            with: [
                'invoice' => $this->invoice,
                'client' => $this->invoice->client,
                'reminder' => $this->reminder,
                'paymentLink' => $this->paymentLink,
                'companyName' => config('app.company_name', 'Zao'),
                'isOverdue' => $this->reminder->type === InvoiceReminder::TYPE_OVERDUE,
                'daysOverdue' => $this->invoice->days_overdue,
            ],
        );
    }

    public function attachments(): array
    {
        $storagePath = "invoices/{$this->invoice->number}.pdf";

        if (! Storage::exists($storagePath)) {
            return [];
        }

        return [
            Attachment::fromStorage($storagePath)
                ->as("Invoice-{$this->invoice->number}.pdf")
                ->withMime('application/pdf'),
        ];
    }
}
