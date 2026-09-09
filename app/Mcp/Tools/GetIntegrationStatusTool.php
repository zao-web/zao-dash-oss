<?php

namespace App\Mcp\Tools;

use App\Models\GitHubInstallation;
use App\Models\GoogleCredential;
use App\Models\HarvestCredential;
use App\Models\NotionConnection;
use App\Models\QuickBooksConnection;
use App\Models\SlackWorkspace;
use App\Models\WiseConnection;
use App\Models\WordPressSite;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetIntegrationStatusTool extends Tool
{
    protected string $name = 'get-integration-status';

    protected string $title = 'Get Integration Status';

    protected string $description = 'Get the connection status of all integrations (Google, Slack, GitHub, Harvest, QuickBooks, etc).';

    public function handle(Request $request): Response|ResponseFactory
    {
        $integrations = [
            'google' => [
                'connected' => GoogleCredential::exists(),
                'count' => GoogleCredential::count(),
            ],
            'slack' => [
                'connected' => SlackWorkspace::exists(),
                'workspaces' => SlackWorkspace::count(),
            ],
            'github' => [
                'connected' => GitHubInstallation::exists(),
                'installations' => GitHubInstallation::count(),
            ],
            'harvest' => [
                'connected' => HarvestCredential::exists(),
            ],
            'quickbooks' => [
                'connected' => QuickBooksConnection::exists(),
            ],
            'notion' => [
                'connected' => NotionConnection::exists(),
                'connections' => NotionConnection::count(),
            ],
            'wordpress' => [
                'connected' => WordPressSite::exists(),
                'sites' => WordPressSite::count(),
            ],
            'wise' => [
                'connected' => WiseConnection::exists(),
            ],
        ];

        $connectedCount = collect($integrations)->where('connected', true)->count();

        return Response::structured([
            'integrations' => $integrations,
            'summary' => [
                'total_integrations' => count($integrations),
                'connected' => $connectedCount,
                'disconnected' => count($integrations) - $connectedCount,
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
