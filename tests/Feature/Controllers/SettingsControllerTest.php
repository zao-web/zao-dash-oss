<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('can access integrations settings page', function () {
    $response = $this->get(route('settings.integrations'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->component('Settings/Integrations')
        ->has('integrations')
    );
});

test('integrations page shows all integration statuses', function () {
    $response = $this->get(route('settings.integrations'));

    $response->assertStatus(200);
    $response->assertInertia(fn ($page) => $page
        ->has('integrations.google')
        ->has('integrations.slack')
        ->has('integrations.github')
        ->has('integrations.harvest')
        ->has('integrations.notion')
        ->has('integrations.wordpress')
        ->has('integrations.quickbooks')
    );
});

test('can get all integration status via api', function () {
    $response = $this->getJson(route('settings.integrationStatus'));

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'google' => ['connected'],
        'slack' => ['connected'],
        'github' => ['connected'],
        'harvest' => ['connected'],
        'notion' => ['connected'],
        'wordpress' => ['connected'],
        'quickbooks' => ['connected'],
    ]);
});

test('integration status shows false when not connected', function () {
    $response = $this->getJson(route('settings.integrationStatus'));

    $response->assertStatus(200);
    expect($response->json('google.connected'))->toBeFalse();
    expect($response->json('slack.connected'))->toBeFalse();
});

test('unauthenticated user cannot access settings', function () {
    auth()->logout();

    $response = $this->get(route('settings.integrations'));

    $response->assertRedirect('/login');
});
