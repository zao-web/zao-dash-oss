<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\ClientNote;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateClientNoteTool extends Tool
{
    protected string $name = 'create-client-note';

    protected string $title = 'Create Client Note';

    protected string $description = 'Log a note about a client. Useful for recording meeting notes, decisions, or important updates.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'content' => 'required|string|max:5000',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $client = Client::findOrFail($request->get('client_id'));

        // Use provided user_id or default to first admin user
        $userId = $request->get('user_id') ?? User::where('role', 'admin')->first()?->id;

        if (! $userId) {
            return Response::error('No user available to attribute this note to. Please provide a user_id or ensure at least one admin user exists.');
        }

        $note = ClientNote::create([
            'client_id' => $client->id,
            'user_id' => $userId,
            'content' => $request->get('content'),
        ]);

        return Response::structured([
            'id' => $note->id,
            'client_id' => $client->id,
            'client_name' => $client->name,
            'content' => $note->content,
            'created_at' => $note->created_at->toIso8601String(),
            'message' => "Note logged for client '{$client->name}'.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('Client ID to log note for'),
            'content' => $schema->string()->required()->description('Note content (meeting notes, decisions, updates, etc.)'),
            'user_id' => $schema->integer()->description('User ID who created the note (optional, defaults to system)'),
        ];
    }
}
