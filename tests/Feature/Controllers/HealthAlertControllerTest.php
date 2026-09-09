<?php

use App\Models\Client;
use App\Models\EscalationTarget;
use App\Models\HealthAlert;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('index returns health alerts', function () {
    HealthAlert::factory()->count(3)->create(['status' => 'active']);

    $response = $this->get(route('health-alerts.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('HealthAlerts/Index')
        ->has('alerts.data')
    );
});

test('index filters by status', function () {
    HealthAlert::factory()->count(2)->create(['status' => 'active']);
    HealthAlert::factory()->count(3)->create(['status' => 'resolved']);

    $response = $this->get(route('health-alerts.index', ['status' => 'resolved']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('alerts.data', 3)
    );
});

test('index filters by severity', function () {
    HealthAlert::factory()->count(2)->create(['severity' => 'critical']);
    HealthAlert::factory()->count(3)->create(['severity' => 'low']);

    $response = $this->get(route('health-alerts.index', ['severity' => 'critical']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('alerts.data', 2)
    );
});

test('index filters by client', function () {
    $client = Client::factory()->create();

    HealthAlert::factory()->count(2)->create(['client_id' => $client->id]);
    HealthAlert::factory()->count(3)->create();

    $response = $this->get(route('health-alerts.index', ['client_id' => $client->id]));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('alerts.data', 2)
    );
});

test('show returns alert details', function () {
    $alert = HealthAlert::factory()->create();

    $response = $this->get(route('health-alerts.show', $alert));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('HealthAlerts/Show')
        ->has('alert')
    );
});

test('can acknowledge alert', function () {
    $alert = HealthAlert::factory()->create(['status' => 'active']);

    $response = $this->post(route('health-alerts.acknowledge', $alert));

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('cannot acknowledge resolved alert', function () {
    $alert = HealthAlert::factory()->create(['status' => 'resolved']);

    $response = $this->post(route('health-alerts.acknowledge', $alert));

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

test('can resolve alert with note', function () {
    $alert = HealthAlert::factory()->create(['status' => 'active']);

    $response = $this->post(route('health-alerts.resolve', $alert), [
        'note' => 'Issue fixed',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('can resolve alert without note', function () {
    $alert = HealthAlert::factory()->create(['status' => 'active']);

    $response = $this->post(route('health-alerts.resolve', $alert));

    $response->assertRedirect();
    $response->assertSessionHas('success');
});

test('cannot resolve already resolved alert', function () {
    $alert = HealthAlert::factory()->create(['status' => 'resolved']);

    $response = $this->post(route('health-alerts.resolve', $alert), [
        'note' => 'Already done',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('error');
});

test('summary endpoint returns json', function () {
    HealthAlert::factory()->count(3)->create();

    $response = $this->get(route('health-alerts.summary'));

    $response->assertOk();
    $response->assertJson([]);
});

test('forClient returns client alerts', function () {
    $client = Client::factory()->create();
    HealthAlert::factory()->count(2)->create(['client_id' => $client->id]);

    $response = $this->get(route('health-alerts.forClient', $client));

    $response->assertOk();
    $response->assertJsonStructure([
        'alerts',
        'unresolved_count',
    ]);
});

test('escalation settings page loads', function () {
    $response = $this->get(route('health-alerts.escalationSettings'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('HealthAlerts/EscalationSettings')
        ->has('levels')
        ->has('users')
    );
});

test('can update escalation targets', function () {
    $users = User::factory()->count(2)->create();

    $response = $this->post(route('health-alerts.updateEscalationTargets'), [
        'level' => 1,
        'user_ids' => $users->pluck('id')->toArray(),
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    expect(EscalationTarget::where('level', 1)->count())->toBe(2);
});

test('update escalation targets requires valid level', function () {
    $response = $this->post(route('health-alerts.updateEscalationTargets'), [
        'level' => 999,
        'user_ids' => [],
    ]);

    $response->assertSessionHasErrors(['level']);
});

test('update escalation targets requires user ids', function () {
    $response = $this->post(route('health-alerts.updateEscalationTargets'), [
        'level' => 1,
    ]);

    $response->assertSessionHasErrors(['user_ids']);
});

test('health alerts require authentication', function () {
    auth()->logout();

    $response = $this->get(route('health-alerts.index'));

    $response->assertRedirect(route('login'));
});
