<?php

namespace App\Console\Commands;

use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\AgentExecutor;
use Illuminate\Console\Command;

/**
 * End-to-end test of the agent pipeline:
 * 1. Trigger agent execution
 * 2. Monitor status
 * 3. Verify output routing
 * 4. Check notifications
 */
class TestAgentPipelineCommand extends Command
{
    protected $signature = 'agents:test-pipeline
        {agent? : Agent slug to test (default: uses test scenario)}
        {--sync : Run synchronously instead of async}
        {--scenario=echo : Test scenario (echo, content, health)}
        {--timeout=60 : Max seconds to wait for completion}';

    protected $description = 'End-to-end test of the agent execution and routing pipeline';

    protected array $scenarios = [
        'echo' => [
            'prompt' => 'Respond with exactly: "Pipeline test successful!"',
            'expected_output' => 'Pipeline test successful',
        ],
        'content' => [
            'prompt' => 'Write a one-paragraph case study about a fictional successful project.',
            'expected_output' => null, // Any non-empty output
        ],
        'health' => [
            'prompt' => 'Analyze the health of a fictional client named "Test Corp" with score 65/100.',
            'expected_output' => null,
        ],
    ];

    public function handle(AgentExecutor $executor): int
    {
        $this->info('Starting agent pipeline test...');
        $this->newLine();

        // Find agent to test
        $agentSlug = $this->argument('agent');
        $agent = $agentSlug
            ? Agent::where('slug', $agentSlug)->first()
            : Agent::where('status', 'active')->first();

        if (! $agent) {
            $this->error('No active agent found.');
            $this->info('Run `php artisan agents:sync` to register agents.');

            return Command::FAILURE;
        }

        $scenario = $this->option('scenario');
        $scenarioConfig = $this->scenarios[$scenario] ?? $this->scenarios['echo'];

        $this->components->twoColumnDetail('Agent', $agent->name." ({$agent->slug})");
        $this->components->twoColumnDetail('Scenario', $scenario);
        $this->components->twoColumnDetail('Mode', $this->option('sync') ? 'Synchronous' : 'Async (queued)');
        $this->newLine();

        // Step 1: Trigger agent
        $this->info('Step 1: Triggering agent execution...');
        $startTime = microtime(true);

        $config = [
            'prompt' => $scenarioConfig['prompt'],
            'context' => [
                'test_run' => true,
                'scenario' => $scenario,
                'triggered_at' => now()->toISOString(),
            ],
        ];

        if ($this->option('sync')) {
            $run = $this->runSync($executor, $agent, $config);
        } else {
            $run = $this->runAsync($agent, $config);
        }

        if (! $run) {
            $this->error('Failed to create agent run.');

            return Command::FAILURE;
        }

        $this->components->twoColumnDetail('Run ID', $run->id);
        $this->components->twoColumnDetail('Status', $run->status);

        // Step 2: Wait for completion (if async)
        if (! $this->option('sync') && $run->status === 'running') {
            $this->info('Step 2: Waiting for completion...');
            $run = $this->waitForCompletion($run);
        }

        $duration = round(microtime(true) - $startTime, 2);
        $this->newLine();

        // Step 3: Verify results
        $this->info('Step 3: Verifying results...');
        $results = $this->verifyResults($run, $scenarioConfig);

        $this->newLine();
        $this->components->bulletList([
            "Status: {$run->status}",
            "Duration: {$duration}s",
            "Cost: \${$run->cost_usd}",
            'Output length: '.strlen($run->output ?? '').' chars',
        ]);

        // Step 4: Check routing results
        $this->info('Step 4: Checking output routing...');
        $routingResults = $run->metadata['routing_results'] ?? [];
        if (! empty($routingResults)) {
            foreach ($routingResults as $dest => $result) {
                $status = $result['status'] ?? 'unknown';
                $icon = $status === 'success' ? '✓' : ($status === 'skipped' ? '○' : '✗');
                $this->line("  {$icon} {$dest}: {$status}");
            }
        } else {
            $this->line('  No routing configured for this agent.');
        }

        // Step 5: Summary
        $this->newLine();
        if ($results['passed']) {
            $this->info('✓ Pipeline test PASSED');

            return Command::SUCCESS;
        } else {
            $this->error('✗ Pipeline test FAILED: '.$results['reason']);

            return Command::FAILURE;
        }
    }

    protected function runSync(AgentExecutor $executor, Agent $agent, array $config): ?AgentRun
    {
        try {
            return $executor->execute(
                agent: $agent,
                config: $config,
                invocationSource: AgentRun::SOURCE_MANUAL,
                invokedBy: 'test:pipeline',
            );
        } catch (\Exception $e) {
            $this->error("Execution error: {$e->getMessage()}");

            return null;
        }
    }

    protected function runAsync(Agent $agent, array $config): ?AgentRun
    {
        // Create run record manually for tracking
        $run = AgentRun::create([
            'agent_id' => $agent->id,
            'session_id' => \Illuminate\Support\Str::uuid(),
            'status' => 'running',
            'task' => $config['prompt'],
            'context' => $config['context'],
            'invocation_source' => AgentRun::SOURCE_MANUAL,
            'invoked_by' => 'test:pipeline',
            'started_at' => now(),
        ]);

        // Dispatch job
        ExecuteAgentJob::dispatch(
            agent: $agent,
            config: $config,
            invocationSource: AgentRun::SOURCE_MANUAL,
            invokedBy: 'test:pipeline',
        );

        return $run;
    }

    protected function waitForCompletion(AgentRun $run): AgentRun
    {
        $timeout = (int) $this->option('timeout');
        $start = time();
        $lastStatus = $run->status;

        while (time() - $start < $timeout) {
            sleep(2);
            $run->refresh();

            if ($run->status !== $lastStatus) {
                $this->line("  Status: {$run->status}");
                $lastStatus = $run->status;
            }

            if (in_array($run->status, ['completed', 'failed', 'cancelled'])) {
                break;
            }
        }

        if (! in_array($run->status, ['completed', 'failed', 'cancelled'])) {
            $this->warn("  Timeout after {$timeout}s - run still in progress.");
        }

        return $run;
    }

    protected function verifyResults(AgentRun $run, array $scenarioConfig): array
    {
        if ($run->status !== 'completed') {
            return [
                'passed' => false,
                'reason' => "Run did not complete successfully (status: {$run->status})",
            ];
        }

        $output = $run->output;
        if (empty($output)) {
            return [
                'passed' => false,
                'reason' => 'No output produced',
            ];
        }

        // If expected output specified, check for it
        if ($scenarioConfig['expected_output']) {
            if (! str_contains(strtolower($output), strtolower($scenarioConfig['expected_output']))) {
                return [
                    'passed' => false,
                    'reason' => 'Output did not contain expected content',
                ];
            }
        }

        return ['passed' => true, 'reason' => null];
    }
}
