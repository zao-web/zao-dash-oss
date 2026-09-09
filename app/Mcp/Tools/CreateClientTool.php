<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateClientTool extends Tool
{
    protected string $name = 'create-client';

    protected string $title = 'Create Client';

    protected string $description = 'Create a new client in the system.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'website' => 'nullable|url',
            'status' => 'nullable|in:active,prospect,inactive',
            'slack_channel' => 'nullable|string',
            'default_hourly_rate' => 'nullable|numeric|min:0',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $validated['status'] = $validated['status'] ?? 'active';
        $validated['health_score'] = 75;

        $baseSlug = $validated['slug'];
        $counter = 1;
        while (Client::where('slug', $validated['slug'])->exists()) {
            $validated['slug'] = $baseSlug.'-'.$counter++;
        }

        $client = Client::create($validated);

        return Response::structured([
            'id' => $client->id,
            'name' => $client->name,
            'slug' => $client->slug,
            'status' => $client->status,
            'message' => "Client '{$client->name}' created successfully.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Client company name'),
            'description' => $schema->string()->description('Description of the client'),
            'website' => $schema->string()->format('uri')->description('Client website URL'),
            'status' => $schema->string()->enum(['active', 'prospect', 'inactive'])->description('Client status (default: active)'),
            'slack_channel' => $schema->string()->description('Associated Slack channel name'),
            'default_hourly_rate' => $schema->number()->description('Default hourly rate for billing'),
        ];
    }
}
