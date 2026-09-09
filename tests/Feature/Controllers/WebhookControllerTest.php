<?php

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Note: This replaces the existing WebhookTest.php in tests/Feature/
// Moved to Controllers subdirectory for better organization

test('webhook rejects requests without token', function () {
    $agent = Agent::factory()->create([
        'status' => 'active',
        'webhook_enabled' => true,
        'webhook_token' => hash('sha256', 'test-token'),
    ]);

    $response = $this->postJson("/webhooks/agents/{$agent->slug}", [
        'prompt' => 'Test',
    ]);

    $response->assertStatus(401);
    $response->assertJson(['error' => 'Invalid webhook token']);
});

test('webhook rejects requests with invalid token', function () {
    $agent = Agent::factory()->create([
        'status' => 'active',
        'webhook_enabled' => true,
        'webhook_token' => hash('sha256', 'correct-token'),
    ]);

    $response = $this->postJson("/webhooks/agents/{$agent->slug}", [
        'prompt' => 'Test',
    ], [
        'X-Webhook-Token' => 'wrong-token',
    ]);

    $response->assertStatus(401);
});

test('webhook accepts requests with valid token', function () {
    $token = 'valid-test-token';
    $agent = Agent::factory()->create([
        'status' => 'active',
        'webhook_enabled' => true,
        'webhook_token' => hash('sha256', $token),
        'requires_approval' => false,
    ]);

    $response = $this->postJson("/webhooks/agents/{$agent->slug}", [
        'prompt' => 'Test',
    ], [
        'X-Webhook-Token' => $token,
    ]);

    $response->assertStatus(202);
    $response->assertJsonStructure(['success', 'run_id', 'status']);
});

test('webhook rejects inactive agent', function () {
    $token = 'test-token';
    $agent = Agent::factory()->create([
        'status' => 'paused',
        'webhook_enabled' => true,
        'webhook_token' => hash('sha256', $token),
    ]);

    $response = $this->postJson("/webhooks/agents/{$agent->slug}", [
        'prompt' => 'Test',
    ], [
        'X-Webhook-Token' => $token,
    ]);

    $response->assertStatus(400);
    $response->assertJson(['error' => 'Agent is not active']);
});

test('webhook validates prompt max length', function () {
    $token = 'test-token';
    $agent = Agent::factory()->create([
        'status' => 'active',
        'webhook_enabled' => true,
        'webhook_token' => hash('sha256', $token),
    ]);

    $response = $this->postJson("/webhooks/agents/{$agent->slug}", [
        'prompt' => str_repeat('a', 10001),
    ], [
        'X-Webhook-Token' => $token,
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['prompt']);
});

test('webhook allows request when no ip restriction set', function () {
    $token = 'test-token';
    $agent = Agent::factory()->create([
        'status' => 'active',
        'webhook_enabled' => true,
        'webhook_token' => hash('sha256', $token),
        'webhook_allowed_ips' => null,
        'requires_approval' => false,
    ]);

    $response = $this->postJson("/webhooks/agents/{$agent->slug}", [
        'prompt' => 'Test',
    ], [
        'X-Webhook-Token' => $token,
    ]);

    $response->assertStatus(202);
});

test('webhook rejects request from non allowed ip', function () {
    $token = 'test-token';
    $agent = Agent::factory()->create([
        'status' => 'active',
        'webhook_enabled' => true,
        'webhook_token' => hash('sha256', $token),
        'webhook_allowed_ips' => ['192.168.1.1'],
    ]);

    $response = $this->postJson("/webhooks/agents/{$agent->slug}", [
        'prompt' => 'Test',
    ], [
        'X-Webhook-Token' => $token,
    ]);

    $response->assertStatus(403);
    $response->assertJson(['error' => 'IP address not allowed']);
});

test('can get webhook run status', function () {
    $run = AgentRun::factory()->create([
        'status' => 'completed',
    ]);

    $response = $this->getJson("/webhooks/status/{$run->id}");

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'run_id',
        'agent',
        'status',
        'started_at',
        'completed_at',
        'duration_ms',
    ]);
});

test('can regenerate webhook token', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $agent = Agent::factory()->create();

    $response = $this->postJson("/agents/{$agent->id}/webhook/regenerate");

    $response->assertStatus(200);
    $response->assertJsonStructure(['token', 'webhook_url', 'message']);
    expect(strlen($response->json('token')))->toBe(64);
});

test('can disable webhook', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $agent = Agent::factory()->create([
        'webhook_enabled' => true,
        'webhook_token' => 'some-token',
    ]);

    $response = $this->postJson("/agents/{$agent->id}/webhook/disable");

    $response->assertStatus(200);

    $agent->refresh();
    expect($agent->webhook_enabled)->toBeFalse();
    expect($agent->webhook_token)->toBeNull();
});

test('can get webhook config', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $agent = Agent::factory()->create([
        'webhook_enabled' => true,
        'webhook_token' => 'some-token',
        'webhook_allowed_ips' => ['10.0.0.1'],
    ]);

    $response = $this->getJson("/agents/{$agent->id}/webhook/config");

    $response->assertStatus(200);
    $response->assertJsonStructure([
        'agent',
        'webhook_enabled',
        'webhook_url',
        'has_token',
        'allowed_ips',
        'has_ip_restriction',
    ]);
    expect($response->json('has_ip_restriction'))->toBeTrue();
});

test('can update webhook ip allowlist', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $agent = Agent::factory()->create([
        'webhook_enabled' => true,
    ]);

    $response = $this->postJson("/agents/{$agent->id}/webhook/allowlist", [
        'allowed_ips' => ['192.168.1.1', '10.0.0.1/24'],
    ]);

    $response->assertStatus(200);
    $response->assertJson(['message' => 'IP allowlist updated']);

    $agent->refresh();
    expect($agent->webhook_allowed_ips)->toBe(['192.168.1.1', '10.0.0.1/24']);
});

test('webhook allowlist validates ip format', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $agent = Agent::factory()->create();

    $response = $this->postJson("/agents/{$agent->id}/webhook/allowlist", [
        'allowed_ips' => ['not-an-ip'],
    ]);

    $response->assertStatus(422);
    $response->assertJsonStructure(['error']);
});

test('webhook accepts context and metadata', function () {
    $token = 'test-token';
    $agent = Agent::factory()->create([
        'status' => 'active',
        'webhook_enabled' => true,
        'webhook_token' => hash('sha256', $token),
        'requires_approval' => false,
    ]);

    $response = $this->postJson("/webhooks/agents/{$agent->slug}", [
        'prompt' => 'Test',
        'context' => ['key' => 'value'],
        'metadata' => ['source' => 'zapier'],
    ], [
        'X-Webhook-Token' => $token,
    ]);

    $response->assertStatus(202);
});

test('webhook records source header', function () {
    $token = 'test-token';
    $agent = Agent::factory()->create([
        'status' => 'active',
        'webhook_enabled' => true,
        'webhook_token' => hash('sha256', $token),
        'requires_approval' => false,
    ]);

    $response = $this->postJson("/webhooks/agents/{$agent->slug}", [
        'prompt' => 'Test',
    ], [
        'X-Webhook-Token' => $token,
        'X-Webhook-Source' => 'github-actions',
    ]);

    $response->assertStatus(202);
});
