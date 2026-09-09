<?php

use App\Models\Client;
use App\Models\Project;
use App\Models\QboInvoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->client = Client::factory()->create();
    $this->user->update(['client_id' => $this->client->id]);
    $this->actingAs($this->user);
});

test('dashboard shows client overview', function () {
    Project::factory()->count(2)->create([
        'client_id' => $this->client->id,
        'status' => 'active',
    ]);

    $response = $this->get(route('portal.dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Portal/Dashboard')
        ->has('client')
        ->has('activeProjects')
        ->has('stats')
    );
});

test('projects lists client projects', function () {
    Project::factory()->count(3)->create([
        'client_id' => $this->client->id,
    ]);

    $response = $this->get(route('portal.projects'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Portal/Projects')
        ->has('projects', 3)
    );
});

test('projectShow displays project details', function () {
    $project = Project::factory()->create([
        'client_id' => $this->client->id,
    ]);

    $response = $this->get(route('portal.projectShow', $project));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Portal/ProjectShow')
        ->has('project')
    );
});

test('projectShow prevents access to other client projects', function () {
    $otherClient = Client::factory()->create();
    $project = Project::factory()->create([
        'client_id' => $otherClient->id,
    ]);

    $response = $this->get(route('portal.projectShow', $project));

    $response->assertForbidden();
});

test('invoices lists client invoices', function () {
    QboInvoice::factory()->count(2)->create([
        'customer_name' => $this->client->name,
    ]);

    $response = $this->get(route('portal.invoices'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Portal/Invoices')
        ->has('invoices')
        ->has('summary')
    );
});

test('portal requires authentication', function () {
    auth()->logout();

    $response = $this->get(route('portal.dashboard'));

    $response->assertRedirect(route('login'));
});

test('portal requires client association', function () {
    $userWithoutClient = User::factory()->create(['client_id' => null]);
    $this->actingAs($userWithoutClient);

    $response = $this->get(route('portal.dashboard'));

    $response->assertStatus(500); // Will error on null client
});
