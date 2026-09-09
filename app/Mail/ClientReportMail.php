<?php

namespace App\Mail;

use App\Mail\Concerns\CcsInternalStakeholders;
use App\Models\ClientReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class ClientReportMail extends Mailable
{
    use CcsInternalStakeholders, Queueable, SerializesModels;

    public function __construct(
        public ClientReport $report
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '%s - %s Report',
                $this->report->client->name,
                $this->report->period_label
            ),
            cc: $this->internalCcs(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.client-report',
            with: [
                'report' => $this->report,
                'client' => $this->report->client,
            ],
        );
    }

    public function attachments(): array
    {
        if (! $this->report->pdf_path) {
            return [];
        }

        $disk = Storage::disk($this->report->pdf_disk ?? 'local');

        if (! $disk->exists($this->report->pdf_path)) {
            return [];
        }

        return [
            Attachment::fromStorage($this->report->pdf_path)
                ->as(sprintf(
                    '%s-%s-Report.pdf',
                    str_replace(' ', '-', $this->report->client->name),
                    $this->report->period_start->format('Y-m')
                ))
                ->withMime('application/pdf'),
        ];
    }
}
