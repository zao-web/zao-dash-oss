<?php

namespace App\Console\Commands;

use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\AgentExecutor;
use Illuminate\Console\Command;

class RunAgentCommand extends Command
{
    protected $signature = 'agent:run
        {slug : The agent slug to run}
        {--prompt= : Optional prompt to pass to the agent}
        {--sync : Run synchronously instead of queuing}';

    protected $description = 'Manually trigger an agent to run';

    public function handle(AgentExecutor $executor): int
    {
        $slug = $this->argument('slug');
        $prompt = $this->option('prompt') ?? 'Manual execution via CLI';

        $agent = Agent::where('slug', $slug)->first();

        if (! $agent) {
            $this->error("Agent '{$slug}' not found.");
            $this->newLine();
            $this->info('Available agents:');
            Agent::orderBy('name')->get()->each(function ($a) {
                $status = match ($a->status) {
                    'active' => '<fg=green>active</>',
                    'paused' => '<fg=yellow>paused</>',
                    default => "<fg=gray>{$a->status}</>",
                };
                $this->line("  - {$a->slug} ({$status})");
            });

            return Command::FAILURE;
        }

        if ($agent->status !== 'active') {
            $this->warn("Agent '{$agent->name}' is currently {$agent->status}.");
            if (! $this->confirm('Run anyway?', false)) {
                return Command::FAILURE;
            }
        }

        $this->info("Triggering agent: {$agent->name}");
        $this->line("  Slug: {$agent->slug}");
        $this->line("  Model: {$agent->model}");
        $this->line("  Prompt: {$prompt}");
        $this->newLine();

        $config = ['prompt' => $prompt];
        $invokedBy = 'cli:'.get_current_user();
        $triggerMetadata = [
            'command' => 'agent:run',
            'triggered_at' => now()->toIso8601String(),
        ];

        if ($this->option('sync')) {
            $this->warn('Running synchronously (this may take a while)...');

            $run = $executor->execute(
                agent: $agent,
                config: $config,
                invocationSource: AgentRun::SOURCE_MANUAL,
                invokedBy: $invokedBy,
                triggerMetadata: $triggerMetadata,
            );

            if ($run->status === 'completed') {
                $this->info('Agent completed successfully!');
                if ($run->result) {
                    $this->newLine();
                    $this->line('Result:');
                    $this->line(json_encode($run->result, JSON_PRETTY_PRINT));
                }
            } else {
                $this->error("Agent finished with status: {$run->status}");
                if ($run->error) {
                    $this->line("Error: {$run->error}");
                }
            }
        } else {
            ExecuteAgentJob::dispatch(
                agent: $agent,
                config: $config,
                invocationSource: AgentRun::SOURCE_MANUAL,
                invokedBy: $invokedBy,
                triggerMetadata: $triggerMetadata,
            );

            $this->info('Agent job dispatched to queue.');
            $this->line('Run `php artisan queue:work` to process the job.');
        }

        return Command::SUCCESS;
    }
}
