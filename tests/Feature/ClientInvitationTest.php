<?php

use App\Mail\ClientPortalInvitation;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

it('sends invitation email when inviting a client contact', function () {
    Mail::fake();

    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();
    $contact = ClientContact::factory()->create([
        'client_id' => $client->id,
        'email' => 'testcontact@example.com',
    ]);

    $response = $this->actingAs($user)
        ->post("/clients/{$client->id}/invite", [
            'email' => 'testcontact@example.com',
            'contact_id' => $contact->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    Mail::assertSent(ClientPortalInvitation::class, function ($mail) {
        return $mail->hasTo('testcontact@example.com');
    });
});

it('blocks duplicate pending invitations', function () {
    Mail::fake();

    $user = User::factory()->create(['role' => 'owner']);
    $client = Client::factory()->create();

    // Send first invitation
    $this->actingAs($user)
        ->post("/clients/{$client->id}/invite", [
            'email' => 'test@example.com',
        ]);

    // Try duplicate
    $response = $this->actingAs($user)
        ->post("/clients/{$client->id}/invite", [
            'email' => 'test@example.com',
        ]);

    $response->assertSessionHasErrors('email');
    Mail::assertSent(ClientPortalInvitation::class, 1);
});
