<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_loads_cost_tracking_page(): void
    {
        $response = $this->get('/costs');

        $response->assertStatus(200);
    }

    public function test_accepts_period_parameter(): void
    {
        $response = $this->get('/costs?period=7');

        $response->assertStatus(200);
    }

    public function test_calculates_total_cost_correctly(): void
    {
        $agent = Agent::factory()->create();

        // Create runs with known costs
        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'cost_usd' => 1.50,
            'status' => 'completed',
        ]);
        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'cost_usd' => 2.50,
            'status' => 'completed',
        ]);

        $response = $this->getJson('/api/costs/summary?period=30');

        $response->assertStatus(200);
        $this->assertEquals(4.0, $response->json('total_cost'));
        $this->assertEquals(2, $response->json('total_runs'));
    }

    public function test_calculates_average_cost_correctly(): void
    {
        $agent = Agent::factory()->create();

        AgentRun::factory()->create(['agent_id' => $agent->id, 'cost_usd' => 2.00]);
        AgentRun::factory()->create(['agent_id' => $agent->id, 'cost_usd' => 4.00]);

        $response = $this->getJson('/api/costs/summary');

        $response->assertStatus(200);
        $this->assertEquals(3.0, $response->json('avg_cost'));
    }

    public function test_filters_by_period_correctly(): void
    {
        $agent = Agent::factory()->create();

        // Old run (outside period)
        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'cost_usd' => 10.00,
            'created_at' => now()->subDays(40),
        ]);

        // Recent run (within period)
        AgentRun::factory()->create([
            'agent_id' => $agent->id,
            'cost_usd' => 5.00,
            'created_at' => now()->subDays(5),
        ]);

        $response = $this->getJson('/api/costs/summary?period=30');

        $response->assertStatus(200);
        // Should only include the recent run
        $this->assertEquals(5.0, $response->json('total_cost'));
        $this->assertEquals(1, $response->json('total_runs'));
    }
}
