<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_requests_without_token(): void
    {
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
    }

    public function test_rejects_requests_with_invalid_token(): void
    {
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
    }

    public function test_accepts_requests_with_valid_token(): void
    {
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
    }

    public function test_allows_request_when_no_ip_restriction_set(): void
    {
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
    }

    public function test_rejects_request_from_non_allowed_ip(): void
    {
        $token = 'test-token';
        $agent = Agent::factory()->create([
            'status' => 'active',
            'webhook_enabled' => true,
            'webhook_token' => hash('sha256', $token),
            'webhook_allowed_ips' => ['192.168.1.1'],
        ]);

        // Request comes from 127.0.0.1 in tests
        $response = $this->postJson("/webhooks/agents/{$agent->slug}", [
            'prompt' => 'Test',
        ], [
            'X-Webhook-Token' => $token,
        ]);

        $response->assertStatus(403);
        $response->assertJson(['error' => 'IP address not allowed']);
    }

    public function test_regenerates_webhook_token(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $agent = Agent::factory()->create();

        $response = $this->postJson("/agents/{$agent->id}/webhook/regenerate");

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'webhook_url', 'message']);
        $this->assertEquals(64, strlen($response->json('token')));
    }

    public function test_disables_webhook(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $agent = Agent::factory()->create([
            'webhook_enabled' => true,
            'webhook_token' => 'some-token',
        ]);

        $response = $this->postJson("/agents/{$agent->id}/webhook/disable");

        $response->assertStatus(200);

        $agent->refresh();
        $this->assertFalse($agent->webhook_enabled);
        $this->assertNull($agent->webhook_token);
    }

    public function test_returns_webhook_config(): void
    {
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
        $this->assertTrue($response->json('has_ip_restriction'));
    }
}
