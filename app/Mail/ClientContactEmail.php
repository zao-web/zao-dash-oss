<?php

namespace App\Mail;

use App\Mail\Concerns\CcsInternalStakeholders;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientContactEmail extends Mailable
{
    use CcsInternalStakeholders, Queueable, SerializesModels;

    public function __construct(
        public Client $client,
        public ClientContact $contact,
        public User $sender,
        public string $emailSubject,
        public string $emailBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
            replyTo: [$this->sender->email],
            cc: $this->internalCcs(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.client-contact',
            with: [
                'body' => $this->emailBody,
                'senderName' => $this->sender->name,
                'clientName' => $this->client->name,
                'contactName' => $this->contact->name,
            ],
        );
    }
}
