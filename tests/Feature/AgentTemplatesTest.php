<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_lists_all_templates(): void
    {
        AgentTemplate::factory()->count(3)->create();

        $response = $this->getJson('/api/agent-templates');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());
    }

    public function test_returns_template_categories(): void
    {
        AgentTemplate::factory()->create(['category' => 'automation']);
        AgentTemplate::factory()->create(['category' => 'content']);
        AgentTemplate::factory()->create(['category' => 'automation']); // Duplicate

        $response = $this->getJson('/api/agent-templates/categories');

        $response->assertStatus(200);
        // Categories endpoint returns objects with category and count
        $categories = collect($response->json())->pluck('category')->toArray();
        $this->assertContains('automation', $categories);
        $this->assertContains('content', $categories);
    }

    public function test_shows_single_template(): void
    {
        $template = AgentTemplate::factory()->create([
            'name' => 'Test Template',
            'slug' => 'test-template',
        ]);

        $response = $this->getJson("/api/agent-templates/{$template->id}");

        $response->assertStatus(200);
        $response->assertJson(['name' => 'Test Template']);
    }

    public function test_creates_agent_from_template(): void
    {
        $template = AgentTemplate::factory()->create([
            'default_model' => 'opus',
            'default_budget_usd' => 15,
            'default_requires_approval' => true,
            'default_tools' => ['web_search', 'file_ops'],
            'system_prompt_template' => 'Template system prompt',
        ]);

        $response = $this->postJson("/api/agent-templates/{$template->id}/create-agent", [
            'name' => 'My New Agent',
        ]);

        $response->assertStatus(201);

        $agent = Agent::where('name', 'My New Agent')->first();
        $this->assertNotNull($agent);
        $this->assertEquals('opus', $agent->model);
        $this->assertTrue($agent->requires_approval);
    }

    public function test_increments_template_usage_count_when_creating_agent(): void
    {
        $template = AgentTemplate::factory()->create([
            'usage_count' => 5,
        ]);

        $this->postJson("/api/agent-templates/{$template->id}/create-agent", [
            'name' => 'New Agent',
        ]);

        $template->refresh();
        $this->assertEquals(6, $template->usage_count);
    }

    public function test_loads_templates_page(): void
    {
        AgentTemplate::factory()->count(2)->create();

        $response = $this->get('/agents/templates');

        $response->assertStatus(200);
    }
}
