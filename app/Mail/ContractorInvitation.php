<?php

namespace App\Mail;

use App\Models\Contractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContractorInvitation extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Contractor $contractor,
        public string $resetUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to Zao - Contractor Portal Access',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.contractor-invitation',
            with: [
                'contractor' => $this->contractor,
                'resetUrl' => $this->resetUrl,
                'companyName' => config('app.name'),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
