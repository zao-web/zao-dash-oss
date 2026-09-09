<?php

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentTask;
use App\Models\Client;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Agents\AgentExecutor;
use App\Services\TaskAgentService;
use App\Services\TimeEstimationService;
use App\Services\Vault\VaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskAgentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected TaskAgentService $service;

    protected AgentExecutor $mockExecutor;

    protected VaultService $mockVault;

    protected TimeEstimationService $timeEstimator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockExecutor = $this->createMock(AgentExecutor::class);
        $this->mockVault = $this->createMock(VaultService::class);
        $this->timeEstimator = new TimeEstimationService;

        $this->service = new TaskAgentService(
            executor: $this->mockExecutor,
            vault: $this->mockVault,
            timeEstimator: $this->timeEstimator,
            harvest: null
        );
    }

    /** @test */
    public function it_assigns_agent_to_task()
    {
        $agent = Agent::factory()->active()->create();
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->id]);
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'title' => 'Fix login bug',
            'description' => 'Users cannot login with SSO',
        ]);
        $user = User::factory()->create();

        $agentTask = $this->service->assignAgentToTask($task, $agent, $user);

        $this->assertInstanceOf(AgentTask::class, $agentTask);
        $this->assertEquals($agent->id, $agentTask->agent_id);
        $this->assertEquals($task->id, $agentTask->task_id);
        $this->assertEquals($project->id, $agentTask->project_id);
        $this->assertEquals($client->id, $agentTask->client_id);
        $this->assertEquals(AgentTask::STATUS_PENDING, $agentTask->status);
        $this->assertEquals($user->id, $agentTask->assigned_by);
    }

    /** @test */
    public function it_logs_activity_when_assigning_agent()
    {
        $agent = Agent::factory()->active()->create();
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        $user = User::factory()->create();

        $this->service->assignAgentToTask($task, $agent, $user);

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'activity_type' => 'agent_assigned',
        ]);
    }

    /** @test */
    public function it_builds_task_description_with_project_info()
    {
        $agent = Agent::factory()->active()->create();
        $project = Project::factory()->create([
            'name' => 'Test Project',
            'github_repo' => 'owner/repo',
        ]);
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'title' => 'Add feature',
            'description' => 'Add new authentication',
        ]);

        $agentTask = $this->service->assignAgentToTask($task, $agent);

        $this->assertStringContainsString('Add feature', $agentTask->task_description);
        $this->assertStringContainsString('Add new authentication', $agentTask->task_description);
        $this->assertStringContainsString('Test Project', $agentTask->task_description);
        $this->assertStringContainsString('owner/repo', $agentTask->task_description);
    }

    /** @test */
    public function it_executes_agent_task()
    {
        $agent = Agent::factory()->active()->create();
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        $agentTask = AgentTask::factory()->forTask($task)->create([
            'agent_id' => $agent->id,
        ]);

        $mockRun = AgentRun::factory()->pending()->make([
            'agent_id' => $agent->id,
        ]);

        $this->mockVault->method('getAgentSecrets')->willReturn([]);
        $this->mockExecutor->method('execute')->willReturn($mockRun);

        $run = $this->service->executeAgentTask($agentTask);

        $this->assertInstanceOf(AgentRun::class, $run);
    }

    /** @test */
    public function it_loads_credentials_from_vault_when_executing()
    {
        $agent = Agent::factory()->active()->create(['slug' => 'dev-agent']);
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        $agentTask = AgentTask::factory()->forTask($task)->create([
            'agent_id' => $agent->id,
            'project_id' => $project->id,
        ]);

        $mockRun = AgentRun::factory()->pending()->make([
            'agent_id' => $agent->id,
        ]);

        $this->mockVault->expects($this->once())
            ->method('getAgentSecrets')
            ->with(
                $this->equalTo('dev-agent'),
                $this->equalTo($project->id),
                $this->anything()
            )
            ->willReturn(['GITHUB_TOKEN' => 'ghp_xxx']);

        $this->mockExecutor->method('execute')->willReturn($mockRun);

        $this->service->executeAgentTask($agentTask);
    }

    /** @test */
    public function it_handles_agent_completion_successfully()
    {
        $agent = Agent::factory()->active()->create();
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        $agentTask = AgentTask::factory()->running()->forTask($task)->create([
            'agent_id' => $agent->id,
        ]);

        $run = AgentRun::factory()->completed()->create([
            'agent_id' => $agent->id,
            'duration_ms' => 30000,
            'output' => ['result' => 'Task completed successfully'],
        ]);

        $this->service->handleAgentCompletion($run, $agentTask);

        $agentTask->refresh();
        $this->assertEquals(AgentTask::STATUS_COMPLETED, $agentTask->status);
        $this->assertNotNull($agentTask->estimated_human_hours);
        $this->assertEquals(30, $agentTask->actual_agent_seconds);
    }

    /** @test */
    public function it_handles_agent_failure()
    {
        $agent = Agent::factory()->active()->create();
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        $agentTask = AgentTask::factory()->running()->forTask($task)->create([
            'agent_id' => $agent->id,
        ]);

        $run = AgentRun::factory()->failed()->create([
            'agent_id' => $agent->id,
            'output' => ['error' => 'API rate limit exceeded'],
        ]);

        $this->service->handleAgentCompletion($run, $agentTask);

        $agentTask->refresh();
        $this->assertEquals(AgentTask::STATUS_FAILED, $agentTask->status);
        $this->assertEquals(['error' => 'API rate limit exceeded'], $agentTask->result);
    }

    /** @test */
    public function it_logs_activity_on_completion()
    {
        $agent = Agent::factory()->active()->create();
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        $agentTask = AgentTask::factory()->running()->forTask($task)->create([
            'agent_id' => $agent->id,
        ]);

        $run = AgentRun::factory()->completed()->create([
            'agent_id' => $agent->id,
            'duration_ms' => 30000,
        ]);

        $this->service->handleAgentCompletion($run, $agentTask);

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'activity_type' => 'agent_completed',
        ]);
    }

    /** @test */
    public function it_logs_activity_on_failure()
    {
        $agent = Agent::factory()->active()->create();
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        $agentTask = AgentTask::factory()->running()->forTask($task)->create([
            'agent_id' => $agent->id,
        ]);

        $run = AgentRun::factory()->failed()->create([
            'agent_id' => $agent->id,
            'output' => ['error' => 'Timeout'],
        ]);

        $this->service->handleAgentCompletion($run, $agentTask);

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'activity_type' => 'agent_failed',
        ]);
    }

    /** @test */
    public function it_detects_pr_creation_in_output()
    {
        $agent = Agent::factory()->active()->create();
        $project = Project::factory()->create();
        $task = Task::factory()->create(['project_id' => $project->id]);
        $agentTask = AgentTask::factory()->running()->forTask($task)->create([
            'agent_id' => $agent->id,
        ]);

        $run = AgentRun::factory()->completed()->create([
            'agent_id' => $agent->id,
            'output' => [
                'pr_url' => 'https://github.com/owner/repo/pull/123',
                'pr_number' => 123,
            ],
        ]);

        $this->service->handleAgentCompletion($run, $agentTask);

        $this->assertDatabaseHas('task_activities', [
            'task_id' => $task->id,
            'activity_type' => 'pr_created',
        ]);
    }

    /** @test */
    public function it_maps_task_priority_correctly()
    {
        $agent = Agent::factory()->active()->create();
        $project = Project::factory()->create();

        $urgentTask = Task::factory()->create([
            'project_id' => $project->id,
            'priority' => 'urgent',
        ]);
        $urgentAgentTask = $this->service->assignAgentToTask($urgentTask, $agent);
        $this->assertEquals(AgentTask::PRIORITY_URGENT, $urgentAgentTask->priority);

        $highTask = Task::factory()->create([
            'project_id' => $project->id,
            'priority' => 'high',
        ]);
        $highAgentTask = $this->service->assignAgentToTask($highTask, $agent);
        $this->assertEquals(AgentTask::PRIORITY_HIGH, $highAgentTask->priority);

        $lowTask = Task::factory()->create([
            'project_id' => $project->id,
            'priority' => 'low',
        ]);
        $lowAgentTask = $this->service->assignAgentToTask($lowTask, $agent);
        $this->assertEquals(AgentTask::PRIORITY_LOW, $lowAgentTask->priority);

        $defaultTask = Task::factory()->create([
            'project_id' => $project->id,
            'priority' => 'medium',
        ]);
        $defaultAgentTask = $this->service->assignAgentToTask($defaultTask, $agent);
        $this->assertEquals(AgentTask::PRIORITY_NORMAL, $defaultAgentTask->priority);
    }

    /** @test */
    public function it_builds_execution_context_with_task_info()
    {
        $agent = Agent::factory()->active()->create();
        $client = Client::factory()->create(['name' => 'Acme Corp']);
        $project = Project::factory()->create([
            'client_id' => $client->id,
            'name' => 'Website Redesign',
            'github_repo' => 'acme/website',
        ]);
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'title' => 'Update homepage',
            'priority' => 'high',
        ]);

        $agentTask = $this->service->assignAgentToTask($task, $agent);
        $context = $agentTask->context;

        $this->assertEquals($task->id, $context['task_id']);
        $this->assertEquals('Update homepage', $context['task_title']);
        $this->assertEquals('high', $context['task_priority']);
        $this->assertEquals('Website Redesign', $context['project']['name']);
        $this->assertEquals('acme/website', $context['project']['github_repo']);
        $this->assertEquals('Acme Corp', $context['client']['name']);
    }

    /** @test */
    public function it_returns_estimation_breakdown()
    {
        $agent = Agent::factory()->active()->create();
        $project = Project::factory()->create();
        $task = Task::factory()->create([
            'project_id' => $project->id,
            'title' => 'Fix critical bug',
            'priority' => 'urgent',
        ]);

        $run = AgentRun::factory()->completed()->create([
            'agent_id' => $agent->id,
            'duration_ms' => 60000,
            'output_tokens' => 5000,
        ]);

        $breakdown = $this->service->getEstimationBreakdown($run, $task);

        $this->assertArrayHasKey('base_hours', $breakdown);
        $this->assertArrayHasKey('complexity_multiplier', $breakdown);
        $this->assertArrayHasKey('final_hours', $breakdown);
    }
}
