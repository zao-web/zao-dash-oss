<?php

namespace App\Mail;

use App\Mail\Concerns\CcsInternalStakeholders;
use App\Models\Invoice;
use App\Services\Invoicing\PdfInvoiceGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class InvoiceUpdatedMail extends Mailable
{
    use CcsInternalStakeholders, Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public ?string $paymentLink = null,
        public ?string $updateSummary = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Invoice #%s Updated - %s',
                $this->invoice->number,
                config('app.company_name', 'Zao')
            ),
            cc: $this->internalCcs(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.invoice-updated',
            with: [
                'invoice' => $this->invoice,
                'client' => $this->invoice->client,
                'paymentLink' => $this->paymentLink,
                'publicUrl' => $this->invoice->public_url,
                'companyName' => config('app.company_name', 'Zao'),
                'updateSummary' => $this->updateSummary,
            ],
        );
    }

    public function attachments(): array
    {
        $storagePath = "invoices/{$this->invoice->number}.pdf";

        if (! Storage::exists($storagePath)) {
            // Generate PDF if not exists
            $generator = app(PdfInvoiceGenerator::class);
            $generator->generateAndStore($this->invoice);
        }

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
