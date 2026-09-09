<?php

namespace App\Mail;

use App\Mail\Concerns\CcsInternalStakeholders;
use App\Models\RfpOpportunity;
use App\Models\RfpProposal;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class RfpProposalMail extends Mailable
{
    use CcsInternalStakeholders, Queueable, SerializesModels;

    public function __construct(
        public RfpProposal $proposal,
        public RfpOpportunity $opportunity,
        public string $emailSubject,
        public string $emailBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
            cc: $this->internalCcs(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.rfp-proposal',
            with: [
                'body' => $this->emailBody,
                'proposal' => $this->proposal,
                'opportunity' => $this->opportunity,
                'companyName' => config('app.company_name', 'Zao'),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $storagePath = "rfp-proposals/{$this->opportunity->id}/{$this->proposal->id}.pdf";

        if (! Storage::exists($storagePath)) {
            return [];
        }

        $filename = sprintf(
            'Proposal-%s-v%d.pdf',
            \Illuminate\Support\Str::slug($this->opportunity->issuing_organization),
            $this->proposal->version
        );

        return [
            Attachment::fromStorage($storagePath)
                ->as($filename)
                ->withMime('application/pdf'),
        ];
    }
}
