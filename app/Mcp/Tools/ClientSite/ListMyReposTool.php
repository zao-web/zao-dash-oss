<?php

namespace App\Mcp\Tools\ClientSite;

use App\Models\Client;
use App\Models\GitHubRepo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * List only the repositories belonging to the calling client. The query is hard
 * scoped to the token's client_id; there is no client parameter to spoof.
 */
class ListMyReposTool extends Tool
{
    protected string $name = 'list-my-repos';

    protected string $title = 'List My Repositories';

    protected string $description = 'List the GitHub repositories connected to the client this token belongs to.';

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var Client $client */
        $client = $request->user();

        $repos = GitHubRepo::query()
            ->where('client_id', $client->id)
            ->where('is_archived', false)
            ->orderBy('full_name')
            ->get(['id', 'client_id', 'full_name', 'installation_id'])
            ->map(fn (GitHubRepo $r) => [
                'full_name' => $r->full_name,
                'can_create_issues' => (bool) $r->installation_id,
            ])
            ->values();

        return Response::structured([
            'repos' => $repos,
            'total' => $repos->count(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
