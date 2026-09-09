<?php

namespace App\Events;

use App\Models\ClientInvitation;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClientInvitationAccepted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ClientInvitation $invitation,
        public User $newUser,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->invitation->invited_by),
        ];
    }

    public function broadcastWith(): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'client_id' => $this->invitation->client_id,
            'client_name' => $this->invitation->client->name,
            'contact_name' => $this->newUser->name,
            'contact_email' => $this->newUser->email,
            'accepted_at' => $this->invitation->accepted_at->toISOString(),
            'client_url' => route('clients.show', $this->invitation->client->slug),
        ];
    }

    public function broadcastAs(): string
    {
        return 'client.invitation.accepted';
    }
}
