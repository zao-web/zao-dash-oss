<?php

namespace App\Mcp\Tools;

use App\Enums\WebsiteBuildPhase;
use App\Jobs\ExecuteWebsiteBuildPhaseJob;
use App\Models\Agent;
use App\Models\WebsiteProject;
use App\Services\Agents\AgentExecutor;
use App\Services\WebsiteProjectAssetService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class TriggerWebsiteBuildTool extends Tool
{
    protected string $name = 'trigger-website-build';

    protected string $title = 'Trigger Website Build';

    protected string $description = 'Trigger the website builder to start building a website project using chunked phases. Each phase runs for 5-10 minutes max to avoid timeouts.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required_without:slug',
            'slug' => 'required_without:id',
        ]);

        $project = $request->get('id')
            ? WebsiteProject::findOrFail($request->get('id'))
            : WebsiteProject::where('slug', $request->get('slug'))->firstOrFail();

        $force = (bool) $request->get('force', false);
        $useChunkedBuild = (bool) $request->get('chunked', true);

        if (! $force && $project->isComplete()) {
            return Response::structured([
                'success' => false,
                'message' => "Website project '{$project->name}' is already complete. Use force=true to rebuild.",
                'staging_url' => $project->staging_url,
                'production_url' => $project->production_url,
            ]);
        }

        if (! $force && $project->isInProgress()) {
            return Response::structured([
                'success' => false,
                'message' => "Website project '{$project->name}' is already in progress (status: {$project->status}). Use force=true to restart.",
                'progress' => $project->getProgressPercentage(),
            ]);
        }

        $additionalInstructions = $request->get('instructions');

        $project->update([
            'status' => WebsiteProject::STATUS_ANALYZING,
            'started_at' => now(),
            'last_error' => null,
            'overall_progress' => 0,
            'phase_progress' => [],
        ]);

        broadcast(new \App\Events\WebsiteBuilderMessageReceived(
            project: $project,
            content: "Starting website build for **{$project->name}**...\n\nI'll build your site in phases:\n1. Research & Analysis\n2. Design Blueprint\n3. Theme Generation\n4. Page Building\n5. Quality Assurance\n6. Finalization\n\nYou'll see updates as each phase completes.",
            role: 'assistant',
            metadata: ['phase' => 'initializing', 'action' => 'start']
        ));

        broadcast(new \App\Events\WebsiteBuilderStatusUpdated(
            project: $project,
            status: 'analyzing',
            progress: 0,
            phase: 'research'
        ));

        if ($useChunkedBuild) {
            $this->dispatchChunkedBuild($project, $additionalInstructions);

            return Response::structured([
                'success' => true,
                'project_id' => $project->id,
                'project_name' => $project->name,
                'mode' => 'chunked',
                'phases' => array_map(fn ($p) => $p->value, WebsiteBuildPhase::autonomousBuildPhases()),
                'status' => 'analyzing',
                'message' => "Chunked website build started for '{$project->name}'. Each phase runs independently with 5-10 min timeout. Use get-website-project to check progress.",
            ]);
        }

        return $this->dispatchLegacyBuild($project, $additionalInstructions, $request);
    }

    protected function dispatchChunkedBuild(WebsiteProject $project, ?string $instructions): void
    {
        $firstPhase = WebsiteBuildPhase::Research;

        ExecuteWebsiteBuildPhaseJob::dispatch(
            project: $project,
            phase: $firstPhase,
            additionalInstructions: $instructions
        );
    }

    protected function dispatchLegacyBuild(WebsiteProject $project, ?string $instructions, Request $request): Response|ResponseFactory
    {
        $agent = Agent::where('slug', 'website-builder-orchestrator')
            ->orWhere('slug', 'site-builder-orchestrator')
            ->first();

        if (! $agent) {
            return Response::structured([
                'success' => false,
                'message' => 'Website builder agent not found. Please ensure the website-builder-orchestrator agent is configured.',
            ]);
        }

        if ($agent->status !== 'active') {
            return Response::structured([
                'success' => false,
                'message' => "Website builder agent is not active (status: {$agent->status}).",
            ]);
        }

        $prompt = $this->buildPrompt($project, $instructions);
        $timeoutSeconds = $request->get('timeout_seconds', 1800);

        $config = [
            'prompt' => $prompt,
            'timeout_seconds' => $timeoutSeconds,
            'context' => [
                'project_id' => $project->id,
                'project' => $project->toArray(),
                'project_type' => $project->project_type,
                'source_data' => $project->source_data,
                'assets' => app(WebsiteProjectAssetService::class)->getAssetsForAgent($project),
                'timeout_seconds' => $timeoutSeconds,
            ],
        ];

        dispatch(function () use ($agent, $config, $project) {
            try {
                $run = app(AgentExecutor::class)->execute($agent, $config);

                if ($run->status === 'completed' && $run->output) {
                    $response = is_array($run->output)
                        ? ($run->output['response'] ?? $run->output['result'] ?? json_encode($run->output))
                        : (string) $run->output;

                    broadcast(new \App\Events\WebsiteBuilderMessageReceived(
                        project: $project,
                        content: $response,
                        role: 'assistant',
                        metadata: ['run_id' => $run->id, 'agent' => $agent->name]
                    ));

                    broadcast(new \App\Events\WebsiteBuilderStatusUpdated(
                        project: $project->fresh(),
                        status: $project->fresh()->status,
                        progress: $project->fresh()->overall_progress ?? $project->fresh()->getProgressPercentage()
                    ));
                } elseif ($run->status === 'failed') {
                    $errorMessage = is_array($run->output) && isset($run->output['error'])
                        ? $run->output['error']
                        : 'Agent execution failed';

                    $project->update([
                        'status' => WebsiteProject::STATUS_FAILED,
                        'last_error' => $errorMessage,
                    ]);

                    broadcast(new \App\Events\WebsiteBuilderError(
                        project: $project,
                        message: $errorMessage
                    ));
                }
            } catch (\Exception $e) {
                $project->update([
                    'status' => WebsiteProject::STATUS_FAILED,
                    'last_error' => $e->getMessage(),
                ]);

                broadcast(new \App\Events\WebsiteBuilderError(
                    project: $project,
                    message: $e->getMessage()
                ));
            }
        })->afterResponse();

        return Response::structured([
            'success' => true,
            'project_id' => $project->id,
            'project_name' => $project->name,
            'agent_name' => $agent->name,
            'mode' => 'legacy',
            'status' => 'analyzing',
            'message' => "Website build started for '{$project->name}'. The build is running asynchronously. Use get-website-project to check progress.",
        ]);
    }

    protected function buildPrompt(WebsiteProject $project, ?string $additionalInstructions): string
    {
        $prompt = "Build a website for project: {$project->name}\n\n";
        $prompt .= "Project Type: {$project->project_type}\n";

        if ($project->domain) {
            $prompt .= "Domain: {$project->domain}\n";
        }

        if ($project->source_data) {
            $sourceData = is_array($project->source_data) ? $project->source_data : json_decode($project->source_data, true);

            if (isset($sourceData['brief'])) {
                $prompt .= "\nProject Brief:\n{$sourceData['brief']}\n";
            }

            if (isset($sourceData['url'])) {
                $prompt .= "\nSource URL: {$sourceData['url']}\n";
            }

            if (isset($sourceData['github_repo'])) {
                $prompt .= "\nGitHub Repository: {$sourceData['github_repo']}\n";
            }
        }

        $assets = app(WebsiteProjectAssetService::class)->getAssetsForAgent($project);
        if ($assets['total_count'] > 0) {
            $prompt .= "\n## Project Assets\n";
            $prompt .= "The client has provided {$assets['total_count']} asset(s) for this project.\n\n";

            if (! empty($assets['images'])) {
                $prompt .= '### Images ('.count($assets['images']).")\n";
                foreach ($assets['images'] as $img) {
                    $category = $img['category'] ? " [{$img['category']}]" : '';
                    $desc = $img['description'] ? " - {$img['description']}" : '';
                    $prompt .= "- {$img['filename']}{$category}{$desc}\n";
                    $prompt .= "  Local path: {$img['local_path']}\n";
                }
                $prompt .= "\n";
            }

            if (! empty($assets['documents'])) {
                $prompt .= '### Documents ('.count($assets['documents']).")\n";
                foreach ($assets['documents'] as $doc) {
                    $category = $doc['category'] ? " [{$doc['category']}]" : '';
                    $desc = $doc['description'] ? " - {$doc['description']}" : '';
                    $prompt .= "- {$doc['filename']}{$category}{$desc}\n";
                    $prompt .= "  Local path: {$doc['local_path']}\n";
                }
                $prompt .= "\n";
            }

            $prompt .= "Use the WebsiteBuilderUploadMediaTool to upload these assets to WordPress when needed.\n";
        }

        if ($additionalInstructions) {
            $prompt .= "\nAdditional Instructions:\n{$additionalInstructions}\n";
        }

        return $prompt;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Website project ID'),
            'slug' => $schema->string()->description('Website project slug (alternative to ID)'),
            'instructions' => $schema->string()->description('Additional instructions or requirements for the build'),
            'force' => $schema->boolean()->description('Force restart build even if in progress or complete (default: false)'),
            'timeout_seconds' => $schema->integer()->description('Execution timeout in seconds (default: 1800, max: 1800)'),
        ];
    }
}
