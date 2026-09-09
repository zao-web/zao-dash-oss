<?php

namespace App\Mail;

use App\Http\Controllers\RetainerReportController;
use App\Mail\Concerns\CcsInternalStakeholders;
use App\Models\RetainerPeriod;
use App\Services\Reports\RetainerReportPdfGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class RetainerReportMail extends Mailable
{
    use CcsInternalStakeholders, Queueable, SerializesModels;

    public function __construct(public RetainerPeriod $period) {}

    public function envelope(): Envelope
    {
        $this->period->loadMissing('client');
        $month = \Carbon\Carbon::parse($this->period->period_start)->format('F Y');

        return new Envelope(
            subject: sprintf(
                'Retainer Report — %s — %s',
                $this->period->client?->name ?? 'Client',
                $month,
            ),
            cc: $this->internalCcs(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.retainer-report',
            with: [
                'period' => $this->period,
                'client' => $this->period->client,
                'reportUrl' => RetainerReportController::signedUrlFor($this->period),
                'companyName' => config('app.company_name', 'Zao'),
            ],
        );
    }

    public function attachments(): array
    {
        $generator = app(RetainerReportPdfGenerator::class);
        $storagePath = $generator->getStoragePath($this->period);

        if (! Storage::exists($storagePath)) {
            $generator->generateAndStore($this->period);
        }

        if (! Storage::exists($storagePath)) {
            return [];
        }

        $filename = sprintf(
            'Retainer-Report-%s-%s.pdf',
            str_replace(' ', '-', $this->period->client?->name ?? 'Client'),
            \Carbon\Carbon::parse($this->period->period_start)->format('Y-m'),
        );

        return [
            Attachment::fromStorage($storagePath)
                ->as($filename)
                ->withMime('application/pdf'),
        ];
    }
}
