<?php

namespace App\Mcp\Tools\ClientSite;

use App\Models\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

/**
 * Return the calling client's own profile — derived entirely from the token, so
 * there is no client parameter and no way to read another client. Deliberately
 * minimal: identity, projects, and repos only. No financial or contact PII.
 */
class GetMyClientTool extends Tool
{
    protected string $name = 'get-my-client';

    protected string $title = 'Get My Client Profile';

    protected string $description = 'Get the profile (projects and connected repositories) for the client this token belongs to.';

    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var Client $client */
        $client = $request->user();

        $client->loadMissing(['projects:id,client_id,name,slug,status,type', 'githubRepos:id,client_id,full_name,is_archived']);

        return Response::structured([
            'id' => $client->id,
            'name' => $client->name,
            'slug' => $client->slug,
            'status' => $client->status,
            'projects' => $client->projects->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'status' => $p->status,
                'type' => $p->type,
            ])->values(),
            'repos' => $client->githubRepos
                ->where('is_archived', false)
                ->map(fn ($r) => ['full_name' => $r->full_name])
                ->values(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
