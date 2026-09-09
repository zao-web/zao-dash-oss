<?php

namespace App\Console\Commands;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\ClaudeAgentSdk;
use Illuminate\Console\Command;

class TestAgentSdkCommand extends Command
{
    protected $signature = 'agents:test-sdk
        {--prompt= : Custom prompt to test}
        {--tools : Test with tool calling}
        {--model=sonnet : Model to use (opus, sonnet, haiku)}';

    protected $description = 'Test the Claude Agent SDK (API-based execution)';

    public function handle(ClaudeAgentSdk $sdk): int
    {
        $this->info('Testing Claude Agent SDK...');
        $this->newLine();

        // Create a test agent
        $agent = new Agent([
            'id' => 0,
            'name' => 'SDK Test Agent',
            'slug' => 'sdk-test',
            'model' => $this->option('model'),
            'system_prompt' => 'You are a helpful test agent. Be concise.',
            'tools' => $this->option('tools') ? ['create_task', 'send_notification', 'query_database'] : [],
            'execution_mode' => 'sdk',
        ]);

        // Create a mock run
        $run = new AgentRun([
            'id' => time(),
            'agent_id' => 0,
            'status' => 'running',
        ]);

        $prompt = $this->option('prompt');

        if (! $prompt) {
            $prompt = $this->option('tools')
                ? 'Query the database for all clients and tell me how many there are. If none exist, just say "No clients found".'
                : 'What is 2 + 2? Answer briefly.';
        }

        $this->components->twoColumnDetail('Model', $agent->model);
        $this->components->twoColumnDetail('Tools Enabled', $this->option('tools') ? 'Yes' : 'No');
        $this->components->twoColumnDetail('Prompt', substr($prompt, 0, 80).(strlen($prompt) > 80 ? '...' : ''));
        $this->newLine();

        $this->info('Executing...');
        $startTime = microtime(true);

        $result = $sdk->execute($agent, $run, [
            'prompt' => $prompt,
            'context' => ['test_mode' => true],
        ]);

        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine();
        $this->components->twoColumnDetail('Success', $result->success ? '<fg=green>Yes</>' : '<fg=red>No</>');
        $this->components->twoColumnDetail('Duration', "{$duration}s");
        $this->components->twoColumnDetail('Input Tokens', number_format($result->inputTokens ?? 0));
        $this->components->twoColumnDetail('Output Tokens', number_format($result->outputTokens ?? 0));
        $this->components->twoColumnDetail('Cost', '$'.number_format($result->costUsd, 6));

        if (! empty($result->output['turns'])) {
            $this->components->twoColumnDetail('Turns', $result->output['turns']);
        }

        $this->newLine();
        $this->info('Response:');
        $this->line('─────────────────────────────────────────────────');

        $response = $result->output['response'] ?? $result->output['error'] ?? json_encode($result->output);
        $this->line($response);

        $this->line('─────────────────────────────────────────────────');

        if (! empty($result->output['tool_results'])) {
            $this->newLine();
            $this->info('Tool Calls:');
            foreach ($result->output['tool_results'] as $toolResult) {
                $this->line("  • {$toolResult['tool']}: ".json_encode($toolResult['result']));
            }
        }

        return $result->success ? Command::SUCCESS : Command::FAILURE;
    }
}
