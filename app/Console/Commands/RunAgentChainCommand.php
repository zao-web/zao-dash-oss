<?php

namespace App\Console\Commands;

use App\Models\AgentChain;
use App\Services\Agents\ChainExecutor;
use Illuminate\Console\Command;

class RunAgentChainCommand extends Command
{
    protected $signature = 'agents:chain
        {chain? : Chain slug or template name}
        {--input= : Initial input for the chain}
        {--list : List available chains and templates}
        {--create= : Create chain from template}
        {--validate= : Validate a chain}';

    protected $description = 'Run, list, or manage agent chains';

    public function handle(ChainExecutor $executor): int
    {
        if ($this->option('list')) {
            return $this->listChains($executor);
        }

        if ($template = $this->option('create')) {
            return $this->createFromTemplate($template);
        }

        if ($chainSlug = $this->option('validate')) {
            return $this->validateChain($executor, $chainSlug);
        }

        $chainSlug = $this->argument('chain');
        if (! $chainSlug) {
            $this->error('Provide a chain slug or use --list to see available chains.');

            return Command::FAILURE;
        }

        return $this->runChain($executor, $chainSlug);
    }

    protected function listChains(ChainExecutor $executor): int
    {
        $this->info('Available Chain Templates:');
        $this->newLine();

        foreach ($executor->getTemplates() as $key => $template) {
            $this->components->twoColumnDetail(
                "<comment>{$key}</comment>",
                $template['name'].' - '.$template['description']
            );

            foreach ($template['steps'] as $i => $step) {
                $condition = $step['condition'] ? " (if: {$step['condition']})" : '';
                $this->line("    {$i}. {$step['agent_slug']}{$condition}");
            }
            $this->newLine();
        }

        $this->info('Saved Chains:');
        $this->newLine();

        $chains = AgentChain::where('is_active', true)->get();
        if ($chains->isEmpty()) {
            $this->line('  No saved chains. Use --create=<template> to create one.');
        } else {
            foreach ($chains as $chain) {
                $this->components->twoColumnDetail(
                    "<comment>{$chain->slug}</comment>",
                    "{$chain->name} ({$chain->getStepCount()} steps)"
                );
            }
        }

        return Command::SUCCESS;
    }

    protected function createFromTemplate(string $templateKey): int
    {
        $chain = AgentChain::createFromTemplate($templateKey);

        if (! $chain) {
            $this->error("Template '{$templateKey}' not found.");

            return Command::FAILURE;
        }

        $this->info("Created chain '{$chain->name}' from template.");
        $this->components->twoColumnDetail('Slug', $chain->slug);
        $this->components->twoColumnDetail('Steps', $chain->getStepCount());

        return Command::SUCCESS;
    }

    protected function validateChain(ChainExecutor $executor, string $chainSlug): int
    {
        $chain = AgentChain::where('slug', $chainSlug)->first();
        if (! $chain) {
            $this->error("Chain '{$chainSlug}' not found.");

            return Command::FAILURE;
        }

        $issues = $executor->validateChain($chain);

        if (empty($issues)) {
            $this->info("✓ Chain '{$chain->name}' is valid.");

            return Command::SUCCESS;
        }

        $this->error("Chain '{$chain->name}' has issues:");
        foreach ($issues as $issue) {
            $this->line("  ✗ {$issue}");
        }

        return Command::FAILURE;
    }

    protected function runChain(ChainExecutor $executor, string $chainSlug): int
    {
        // Try to find existing chain or use template
        $chain = AgentChain::where('slug', $chainSlug)->first();
        $isTemplate = false;

        if (! $chain) {
            // Check if it's a template
            $templates = AgentChain::templates();
            if (! isset($templates[$chainSlug])) {
                $this->error("Chain or template '{$chainSlug}' not found.");

                return Command::FAILURE;
            }
            $isTemplate = true;
        }

        // Get input
        $input = $this->option('input');
        if (! $input) {
            $input = $this->ask('Enter initial input for the chain');
        }

        if (! $input) {
            $this->error('Input is required.');

            return Command::FAILURE;
        }

        $this->info('Starting chain execution...');
        $this->newLine();

        if ($isTemplate) {
            $chainRun = $executor->startFromTemplate($chainSlug, $input, 'manual:cli');
        } else {
            $chainRun = $executor->startChain($chain, $input, 'manual:cli');
        }

        if (! $chainRun) {
            $this->error('Failed to start chain.');

            return Command::FAILURE;
        }

        $this->components->twoColumnDetail('Chain Run ID', $chainRun->id);
        $this->components->twoColumnDetail('Status', $chainRun->status);
        $this->components->twoColumnDetail('First Step', 'Queued');

        $this->newLine();
        $this->info('Chain execution started. Monitor with:');
        $this->line("  php artisan agents:chain-status {$chainRun->id}");

        return Command::SUCCESS;
    }
}
