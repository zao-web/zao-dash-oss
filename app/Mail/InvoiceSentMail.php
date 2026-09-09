<?php

namespace App\Mail;

use App\Http\Controllers\RetainerReportController;
use App\Mail\Concerns\CcsInternalStakeholders;
use App\Models\Invoice;
use App\Models\RetainerPeriod;
use App\Services\Invoicing\PdfInvoiceGenerator;
use App\Services\Reports\RetainerReportPdfGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class InvoiceSentMail extends Mailable
{
    use CcsInternalStakeholders, Queueable, SerializesModels;

    public function __construct(
        public Invoice $invoice,
        public ?string $paymentLink = null
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                'Invoice #%s from %s',
                $this->invoice->number,
                config('app.company_name', 'Zao')
            ),
            cc: $this->internalCcs(),
        );
    }

    public function content(): Content
    {
        $period = $this->resolveReportPeriod();
        $retainerReportUrl = $period
            ? RetainerReportController::signedUrlFor($period)
            : null;

        return new Content(
            markdown: 'emails.invoice-sent',
            with: [
                'invoice' => $this->invoice,
                'client' => $this->invoice->client,
                'paymentLink' => $this->paymentLink,
                'companyName' => config('app.company_name', 'Zao'),
                'retainerReportUrl' => $retainerReportUrl,
                'retainerReportPeriod' => $period,
            ],
        );
    }

    public function attachments(): array
    {
        $attachments = [];

        // Invoice PDF (always)
        $invoicePath = "invoices/{$this->invoice->number}.pdf";
        if (! Storage::exists($invoicePath)) {
            app(PdfInvoiceGenerator::class)->generateAndStore($this->invoice);
        }
        if (Storage::exists($invoicePath)) {
            $attachments[] = Attachment::fromStorage($invoicePath)
                ->as("Invoice-{$this->invoice->number}.pdf")
                ->withMime('application/pdf');
        }

        if ($period = $this->resolveReportPeriod()) {
            $reportGenerator = app(RetainerReportPdfGenerator::class);
            $reportPath = $reportGenerator->getStoragePath($period);
            if (! Storage::exists($reportPath)) {
                $reportGenerator->generateAndStore($period);
            }
            if (Storage::exists($reportPath)) {
                $reportFilename = sprintf(
                    'Retainer-Report-%s-%s.pdf',
                    str_replace(' ', '-', $period->client?->name ?? 'Client'),
                    \Carbon\Carbon::parse($period->period_start)->format('Y-m'),
                );
                $attachments[] = Attachment::fromStorage($reportPath)
                    ->as($reportFilename)
                    ->withMime('application/pdf');
            }
        }

        return $attachments;
    }

    /**
     * Resolve which retainer period's report to attach/link to.
     *
     * Always prefer the most recent COMPLETED period for the invoice's
     * client — the client sees what was delivered last month alongside
     * the upcoming month's invoice. This matches forward-billed retainers
     * where invoice.retainer_period_id points at the upcoming (in-progress)
     * period, which has no delivered work to report on yet.
     *
     * Falls back to the invoice's own retainerPeriod (e.g. arrears
     * billing) if there's no prior completed period for this client.
     * Returns null if the invoice has no client or no relevant periods.
     */
    protected function resolveReportPeriod(): ?RetainerPeriod
    {
        $this->invoice->loadMissing('retainerPeriod.client', 'client');

        if (! $clientId = $this->invoice->client_id) {
            return $this->invoice->retainerPeriod;
        }

        $mostRecentCompleted = RetainerPeriod::query()
            ->where('client_id', $clientId)
            ->where('period_end', '<', now()->startOfDay())
            ->orderByDesc('period_end')
            ->first();

        return $mostRecentCompleted ?? $this->invoice->retainerPeriod;
    }
}
