<?php

namespace App\Mcp\Tools;

use App\Jobs\GenerateSeoContentJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DispatchSeoContentJobTool extends Tool
{
    protected string $name = 'dispatch-seo-content-job';

    protected string $title = 'Dispatch SEO Content Generation Job';

    protected string $description = 'Queue a background job to generate a single SEO content piece using the specified playbook and proprietary data.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'keyword' => 'required|string|max:255',
            'playbook' => 'required|string|in:Templates,Tools,Curation,Rankings,Converters,Calculators,Comparisons,Examples,Galleries,Location,Persona,Vertical,Integration,Glossary,Educational,Translations,Directory,Listings,Case Study,Profile',
            'url_slug' => 'required|string|max:255',
            'proprietary_data' => 'required|array',
            'priority' => 'nullable|integer|min:1|max:10',
            'orchestrator_run_id' => 'nullable|integer|exists:agent_runs,id',
        ]);

        // Dispatch the job with priority queue
        $priority = $validated['priority'] ?? 5;
        $queue = $priority <= 3 ? 'high' : ($priority <= 7 ? 'default' : 'low');

        GenerateSeoContentJob::dispatch(
            title: $validated['title'],
            keyword: $validated['keyword'],
            playbook: $validated['playbook'],
            urlSlug: $validated['url_slug'],
            proprietaryData: $validated['proprietary_data'],
            priority: $priority,
            orchestratorRunId: $validated['orchestrator_run_id'] ?? null
        )->onQueue($queue);

        return Response::structured([
            'title' => $validated['title'],
            'keyword' => $validated['keyword'],
            'playbook' => $validated['playbook'],
            'url' => 'example.com/'.$validated['url_slug'],
            'priority' => $priority,
            'queue' => $queue,
            'message' => "SEO content generation job queued for '{$validated['title']}' using {$validated['playbook']} playbook.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required()->description('Page title (e.g., "Laravel Development for Healthcare Startups")'),
            'keyword' => $schema->string()->required()->description('Target keyword (e.g., "laravel healthcare development")'),
            'playbook' => $schema->string()->enum([
                'Templates',
                'Tools',
                'Curation',
                'Rankings',
                'Converters',
                'Calculators',
                'Comparisons',
                'Examples',
                'Galleries',
                'Location',
                'Persona',
                'Vertical',
                'Integration',
                'Glossary',
                'Educational',
                'Translations',
                'Directory',
                'Listings',
                'Case Study',
                'Profile',
            ])->required()->description('Playbook to use for content generation'),
            'url_slug' => $schema->string()->required()->description('URL slug (e.g., "laravel-for-healthcare")'),
            'proprietary_data' => $schema->object()->required()->description('Proprietary data to include (projects, clients, metrics)'),
            'priority' => $schema->integer()->description('Priority (1-10, default: 5). Higher priority = generated first.'),
            'orchestrator_run_id' => $schema->integer()->description('Parent orchestrator agent run ID (optional)'),
        ];
    }
}
