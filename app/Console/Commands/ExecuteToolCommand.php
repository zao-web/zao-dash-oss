<?php

namespace App\Console\Commands;

use App\Agents\ToolRegistry;
use Illuminate\Console\Command;

class ExecuteToolCommand extends Command
{
    protected $signature = 'tool:execute {tool : Tool slug (e.g., website-builder-update-global-styles)} {--params= : JSON-encoded parameters}';

    protected $description = 'Execute an agent tool from the command line. Used by CLI agents to invoke MCP tools.';

    public function __construct(
        protected ToolRegistry $registry
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $toolSlug = $this->argument('tool');
        $paramsJson = $this->option('params') ?? '{}';

        $tool = $this->registry->get($toolSlug);

        if (! $tool) {
            $this->error("Tool not found: {$toolSlug}");
            $this->line('');
            $this->line('Available tools:');
            foreach ($this->registry->all() as $slug => $t) {
                $this->line("  - {$slug}");
            }

            return Command::FAILURE;
        }

        $params = json_decode($paramsJson, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('Invalid JSON in --params: '.json_last_error_msg());

            return Command::FAILURE;
        }

        $this->info("Executing tool: {$tool->name()}");
        $this->line("Parameters: {$paramsJson}");
        $this->line('');

        try {
            $result = $tool->execute($params);

            $this->output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['success'] ?? false ? Command::SUCCESS : Command::FAILURE;
        } catch (\Throwable $e) {
            $this->error("Tool execution failed: {$e->getMessage()}");

            $this->output->writeln(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ], JSON_PRETTY_PRINT));

            return Command::FAILURE;
        }
    }
}
