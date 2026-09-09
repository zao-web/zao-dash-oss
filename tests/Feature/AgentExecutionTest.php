<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_trigger_endpoint_exists_for_active_agent(): void
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'requires_approval' => false,
        ]);

        // Note: trigger endpoint uses slug, not id
        // This tests that the route exists and is accessible
        $response = $this->postJson("/agents/{$agent->slug}/trigger", [
            'prompt' => 'Test prompt',
        ]);

        // Accept both 200 (success) and 500 (execution error) - just not 404
        $this->assertNotEquals(404, $response->status());
    }

    public function test_trigger_returns_error_for_paused_agent(): void
    {
        $agent = Agent::factory()->create([
            'status' => 'paused',
        ]);

        // Note: trigger endpoint uses slug, not id
        $response = $this->postJson("/agents/{$agent->slug}/trigger", [
            'prompt' => 'Test prompt',
        ]);

        // Paused agents should return 200 with failed status
        $response->assertStatus(200);
        $this->assertEquals('failed', $response->json('status'));
    }

    public function test_trigger_returns_404_for_nonexistent_agent(): void
    {
        $response = $this->postJson('/agents/nonexistent-agent/trigger', [
            'prompt' => 'Test prompt',
        ]);

        $response->assertStatus(404);
    }

    public function test_dry_run_returns_validation_without_creating_run(): void
    {
        $agent = Agent::factory()->create([
            'status' => 'active',
            'model' => 'sonnet',
        ]);

        $initialRunCount = AgentRun::count();

        $response = $this->postJson("/agents/{$agent->id}/dry-run", [
            'prompt' => 'Test prompt',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'valid',
            'agent',
            'execution_preview',
            'warnings',
        ]);

        // No new runs should be created
        $this->assertEquals($initialRunCount, AgentRun::count());
    }

    public function test_dry_run_returns_validation_error_for_inactive_agent(): void
    {
        $agent = Agent::factory()->create([
            'status' => 'paused',
        ]);

        $response = $this->postJson("/agents/{$agent->id}/dry-run", [
            'prompt' => 'Test prompt',
        ]);

        $response->assertStatus(200);
        // DryRun returns valid: false with validation.error for inactive agents
        $this->assertFalse($response->json('valid'));
        $this->assertEquals("Agent status is 'paused', must be 'active'", $response->json('validation.error'));
    }

    public function test_clones_agent_with_new_name(): void
    {
        $original = Agent::factory()->create([
            'name' => 'Original Agent',
            'slug' => 'original-agent',
            'model' => 'opus',
            'system_prompt' => 'Test prompt',
        ]);

        // JSON requests return JSON response, not redirect
        $response = $this->postJson("/agents/{$original->id}/clone", [
            'name' => 'Cloned Agent',
        ]);

        $response->assertStatus(200);
        $response->assertJson(['message' => 'Agent cloned successfully']);

        $clone = Agent::where('name', 'Cloned Agent')->first();
        $this->assertNotNull($clone);
        $this->assertEquals('cloned-agent', $clone->slug);
        $this->assertEquals('opus', $clone->model);
        $this->assertEquals('paused', $clone->status); // Clones start paused
    }

    public function test_generates_unique_slug_for_clone(): void
    {
        $original = Agent::factory()->create([
            'name' => 'Test Agent',
            'slug' => 'test-agent',
        ]);

        // Clone with default name
        $this->postJson("/agents/{$original->id}/clone");

        $clone = Agent::where('name', 'Test Agent (Copy)')->first();
        $this->assertNotNull($clone);
        $this->assertNotEquals('test-agent', $clone->slug);
    }
}
