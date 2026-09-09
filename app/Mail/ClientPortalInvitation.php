<?php

namespace App\Mail;

use App\Mail\Concerns\CcsInternalStakeholders;
use App\Models\ClientInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientPortalInvitation extends Mailable
{
    use CcsInternalStakeholders, Queueable, SerializesModels;

    public function __construct(
        public ClientInvitation $invitation,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're invited to the {$this->invitation->client->name} Client Portal",
            cc: $this->internalCcs(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.client-portal-invitation',
            with: [
                'acceptUrl' => route('portal.invite.show', $this->invitation->token),
                'clientName' => $this->invitation->client->name,
                'inviterName' => $this->invitation->invitedBy->name,
                'expiresAt' => $this->invitation->expires_at->format('F j, Y'),
            ],
        );
    }
}
