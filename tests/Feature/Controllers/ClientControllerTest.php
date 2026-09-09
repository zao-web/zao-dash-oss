<?php

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can create client', function () {
    $response = $this->post(route('clients.store'), [
        'name' => 'Test Client',
        'description' => 'Test Description',
        'health_score' => 8.5,
        'status' => 'active',
        'website' => 'https://example.com',
        'slack_channel' => '#test-channel',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Client created successfully.');

    $this->assertDatabaseHas('clients', [
        'name' => 'Test Client',
        'slug' => 'test-client',
        'status' => 'active',
        'website' => 'https://example.com',
    ]);
});

test('client creation requires name', function () {
    $response = $this->post(route('clients.store'), [
        'status' => 'active',
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('client status must be valid', function () {
    $response = $this->post(route('clients.store'), [
        'name' => 'Test Client',
        'status' => 'invalid-status',
    ]);

    $response->assertSessionHasErrors(['status']);
});

test('can create client via json and receive json response', function () {
    $response = $this->postJson(route('clients.store'), [
        'name' => 'API Client',
        'status' => 'active',
    ]);

    $response->assertCreated();
    $response->assertJsonFragment(['name' => 'API Client', 'slug' => 'api-client']);

    $this->assertDatabaseHas('clients', [
        'name' => 'API Client',
        'slug' => 'api-client',
    ]);
});

test('can create client with inline contacts', function () {
    $response = $this->postJson(route('clients.store'), [
        'name' => 'Contact Client',
        'status' => 'active',
        'contacts' => [
            [
                'name' => 'Jane Doe',
                'email' => 'jane@example.com',
                'role' => 'PM',
                'is_primary' => true,
            ],
        ],
    ]);

    $response->assertCreated();

    $client = Client::where('name', 'Contact Client')->first();
    expect($client)->not->toBeNull();
    expect($client->contacts)->toHaveCount(1);
    expect($client->contacts->first()->name)->toBe('Jane Doe');
    expect($client->contacts->first()->email)->toBe('jane@example.com');
});

test('skips empty contacts when creating client', function () {
    $response = $this->postJson(route('clients.store'), [
        'name' => 'No Contacts Client',
        'status' => 'active',
        'contacts' => [
            ['name' => '', 'email' => '', 'role' => '', 'is_primary' => true],
        ],
    ]);

    $response->assertCreated();

    $client = Client::where('name', 'No Contacts Client')->first();
    expect($client->contacts)->toHaveCount(0);
});

test('client health score must be between 0 and 10', function () {
    $response = $this->post(route('clients.store'), [
        'name' => 'Test Client',
        'health_score' => 15,
    ]);

    $response->assertSessionHasErrors(['health_score']);
});

test('client website must be valid url', function () {
    $response = $this->post(route('clients.store'), [
        'name' => 'Test Client',
        'website' => 'not-a-url',
    ]);

    $response->assertSessionHasErrors(['website']);
});

test('can update client', function () {
    $client = Client::factory()->create([
        'name' => 'Old Name',
        'status' => 'active',
    ]);

    $response = $this->put(route('clients.update', $client), [
        'name' => 'New Name',
        'description' => 'Updated Description',
        'health_score' => 7.5,
        'status' => 'inactive',
        'website' => 'https://newsite.com',
        'slack_channel' => '#new-channel',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Client updated successfully.');

    $client->refresh();
    expect($client->name)->toBe('New Name');
    expect($client->status)->toBe('inactive');
    expect($client->health_score)->toBe(7.5);
});

test('updating client name updates slug', function () {
    $client = Client::factory()->create([
        'name' => 'Old Name',
        'slug' => 'old-name',
    ]);

    $this->put(route('clients.update', $client), [
        'name' => 'New Name',
        'description' => 'Description',
        'status' => 'active',
    ]);

    $client->refresh();
    expect($client->slug)->toBe('new-name');
});

test('can delete client', function () {
    $client = Client::factory()->create();

    $response = $this->delete(route('clients.destroy', $client));

    $response->assertRedirect(route('clients.index'));
    $response->assertSessionHas('success', 'Client deleted successfully.');

    $this->assertDatabaseMissing('clients', [
        'id' => $client->id,
    ]);
});

test('can add contact to client', function () {
    $client = Client::factory()->create();

    $response = $this->post(route('clients.addContact', $client), [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'role' => 'CEO',
        'phone' => '123-456-7890',
        'is_primary' => true,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Contact added successfully.');

    $this->assertDatabaseHas('client_contacts', [
        'client_id' => $client->id,
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'role' => 'CEO',
    ]);
});

test('contact creation requires name', function () {
    $client = Client::factory()->create();

    $response = $this->post(route('clients.addContact', $client), [
        'email' => 'john@example.com',
    ]);

    $response->assertSessionHasErrors(['name']);
});

test('contact creation requires valid email', function () {
    $client = Client::factory()->create();

    $response = $this->post(route('clients.addContact', $client), [
        'name' => 'John Doe',
        'email' => 'not-an-email',
    ]);

    $response->assertSessionHasErrors(['email']);
});

test('can update client contact', function () {
    $client = Client::factory()->create();
    $contact = ClientContact::factory()->create([
        'client_id' => $client->id,
        'name' => 'Old Name',
    ]);

    $response = $this->put(route('clients.updateContact', [$client, $contact]), [
        'name' => 'New Name',
        'email' => 'new@example.com',
        'role' => 'CTO',
        'phone' => '999-888-7777',
        'is_primary' => true,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Contact updated successfully.');

    $contact->refresh();
    expect($contact->name)->toBe('New Name');
    expect($contact->email)->toBe('new@example.com');
});

test('cannot update contact from different client', function () {
    $client1 = Client::factory()->create();
    $client2 = Client::factory()->create();
    $contact = ClientContact::factory()->create(['client_id' => $client2->id]);

    $response = $this->put(route('clients.updateContact', [$client1, $contact]), [
        'name' => 'New Name',
        'email' => 'new@example.com',
    ]);

    $response->assertNotFound();
});

test('can remove client contact', function () {
    $client = Client::factory()->create();
    $contact = ClientContact::factory()->create(['client_id' => $client->id]);

    $response = $this->delete(route('clients.removeContact', [$client, $contact]));

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Contact removed successfully.');

    $this->assertDatabaseMissing('client_contacts', [
        'id' => $contact->id,
    ]);
});

test('cannot remove contact from different client', function () {
    $client1 = Client::factory()->create();
    $client2 = Client::factory()->create();
    $contact = ClientContact::factory()->create(['client_id' => $client2->id]);

    $response = $this->delete(route('clients.removeContact', [$client1, $contact]));

    $response->assertNotFound();
});
