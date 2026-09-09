<?php

namespace App\Mcp\Resources;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class CurrentUserResource extends Resource
{
    protected string $name = 'current-user';

    protected string $title = 'Current User';

    protected string $description = 'Information about the currently authenticated user. Use this to identify "me" when the user refers to themselves.';

    protected string $uri = 'user://me';

    protected string $mimeType = 'application/json';

    public function handle(Request $request): Response
    {
        $user = $request->user();

        if (! $user) {
            return Response::error('Not authenticated');
        }

        return Response::json([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'message' => "The current user is {$user->name} (ID: {$user->id}). Use this ID for assignee_id when assigning to 'me'.",
        ]);
    }
}
