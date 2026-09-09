<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class CreateClientContactTool extends Tool
{
    protected string $name = 'create-client-contact';

    protected string $title = 'Create Client Contact';

    protected string $description = 'Add a contact person to a client. Useful for recording key stakeholders, project managers, or billing contacts.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'role' => 'nullable|string|max:255',
            'is_primary' => 'nullable|boolean',
        ]);

        $client = Client::findOrFail($request->get('client_id'));

        // If marking as primary, unset existing primary contacts
        if ($request->get('is_primary')) {
            ClientContact::where('client_id', $client->id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $contact = ClientContact::create([
            'client_id' => $client->id,
            'name' => $request->get('name'),
            'email' => $request->get('email'),
            'phone' => $request->get('phone'),
            'role' => $request->get('role'),
            'is_primary' => $request->get('is_primary', false),
        ]);

        return Response::structured([
            'id' => $contact->id,
            'client_id' => $client->id,
            'client_name' => $client->name,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'role' => $contact->role,
            'is_primary' => $contact->is_primary,
            'message' => "Contact '{$contact->name}' added to client '{$client->name}'.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()->required()->description('Client ID to add contact to'),
            'name' => $schema->string()->required()->description('Contact person name'),
            'email' => $schema->string()->format('email')->description('Contact email address'),
            'phone' => $schema->string()->description('Contact phone number'),
            'role' => $schema->string()->description('Contact role or title (e.g., Project Manager, CEO)'),
            'is_primary' => $schema->boolean()->description('Whether this is the primary contact (default: false)'),
        ];
    }
}
