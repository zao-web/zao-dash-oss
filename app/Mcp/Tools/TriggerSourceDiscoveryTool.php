<?php

namespace App\Mcp\Tools;

use App\Jobs\DiscoverRfpSourcesJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class TriggerSourceDiscoveryTool extends Tool
{
    protected string $name = 'trigger-source-discovery';

    protected string $title = 'Trigger RFP Source Discovery';

    protected string $description = 'Manually trigger the AI-powered RFP source discovery process. This searches the web for new RFP boards, listing sites, and email newsletters matching your target industries (tourism, DMOs, municipalities, nonprofits) and adds them as sources.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'industries' => 'nullable|array',
            'industries.*' => 'string',
        ]);

        DiscoverRfpSourcesJob::dispatch($validated['industries'] ?? []);

        return Response::structured([
            'message' => 'Source discovery job queued. Results will appear in your sources list and you will be notified via Slack when new sources are found.',
            'industries_targeted' => $validated['industries'] ?? ['tourism', 'dmo', 'municipality', 'nonprofit', 'government', 'education'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'industries' => $schema->array()->description('Optional: specific industries to target (defaults to: tourism, DMO, municipality, nonprofit, government, education)'),
        ];
    }
}
