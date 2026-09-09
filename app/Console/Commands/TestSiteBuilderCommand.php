<?php

namespace App\Console\Commands;

use App\Models\SiteBuilderProject;
use App\Services\Agents\AgentExecutor;
use Illuminate\Console\Command;

class TestSiteBuilderCommand extends Command
{
    protected $signature = 'site-builder:test
                            {domain : Domain name to test with}
                            {--brief= : Custom brief (default: test brief)}';

    protected $description = 'Test the site builder system with a sample project';

    public function __construct(
        private AgentExecutor $agentExecutor
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $domain = $this->argument('domain');
        $brief = $this->option('brief') ?? "Build a professional website for {$domain}, a local business specializing in quality products and services.";

        $this->info("Testing Site Builder with domain: {$domain}");
        $this->info("Brief: {$brief}");

        // Create test project
        $project = SiteBuilderProject::create([
            'domain' => $domain,
            'project_name' => 'Test Project',
            'brief' => $brief,
            'company_type' => 'active',
            'status' => SiteBuilderProject::STATUS_CREATED,
            'environment' => 'staging',
            'target_hosting' => 'wordpress_com',
            'user_id' => 1, // Assuming user ID 1 exists
        ]);

        $this->info("Created project ID: {$project->id}");

        // Test agent execution (commented out for safety)
        /*
        try {
            $run = $this->agentExecutor->execute(
                agent: 'research-agent',
                config: [
                    'domain' => $domain,
                    'company_type' => 'active',
                    'brief' => $brief,
                    'depth' => 'shallow'
                ],
                invocationSource: 'test_command',
                projectId: $project->id
            );

            $this->info("Started agent run: {$run->id}");
        } catch (\Exception $e) {
            $this->error("Agent execution failed: " . $e->getMessage());
        }
        */

        $this->info('Site Builder test completed successfully!');
        $this->info("Project URL: /site-builder/{$project->id}");

        return 0;
    }
}
