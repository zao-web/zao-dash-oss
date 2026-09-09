<?php

namespace App\Agents\Tools;

use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Support\Str;

/**
 * Create a new client.
 */
class CreateClientTool extends BaseTool
{
    public function category(): string
    {
        return 'actions';
    }

    public function name(): string
    {
        return 'Create Client';
    }

    public function description(): string
    {
        return 'Create a new client with name and optional primary contact. Contact info (name, email, phone) is stored as a client contact, not on the client directly.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'Client/company name (required)',
                ],
                'contact_name' => [
                    'type' => 'string',
                    'description' => 'Primary contact person name',
                ],
                'contact_email' => [
                    'type' => 'string',
                    'description' => 'Primary contact email address',
                ],
                'contact_phone' => [
                    'type' => 'string',
                    'description' => 'Primary contact phone number',
                ],
                'contact_role' => [
                    'type' => 'string',
                    'description' => 'Primary contact role/title',
                ],
                'website' => [
                    'type' => 'string',
                    'description' => 'Client website URL',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Notes about the client',
                ],
            ],
            'required' => ['name'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'contact_email' => 'nullable|email|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'contact_role' => 'nullable|string|max:255',
            'website' => 'nullable|url|max:255',
            'description' => 'nullable|string',
        ];
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function execute(array $params): array
    {
        // Check for existing client with same name to avoid slug collisions
        $existing = Client::where('slug', Str::slug($params['name']))->first();
        if ($existing) {
            $primaryContact = $existing->contacts()->where('is_primary', true)->first();

            return [
                'created' => false,
                'existing' => true,
                'message' => "A client named \"{$existing->name}\" already exists.",
                'client' => [
                    'id' => $existing->id,
                    'name' => $existing->name,
                    'slug' => $existing->slug,
                    'primary_contact' => $primaryContact?->email,
                ],
            ];
        }

        $client = Client::create([
            'name' => $params['name'],
            'slug' => Str::slug($params['name']),
            'website' => $params['website'] ?? null,
            'description' => $params['description'] ?? null,
            'status' => 'active',
        ]);

        // Create primary contact if contact info provided
        $contact = null;
        if (! empty($params['contact_name']) || ! empty($params['contact_email'])) {
            $contact = ClientContact::create([
                'client_id' => $client->id,
                'name' => $params['contact_name'] ?? $params['name'],
                'email' => $params['contact_email'] ?? '',
                'phone' => $params['contact_phone'] ?? null,
                'role' => $params['contact_role'] ?? null,
                'is_primary' => true,
            ]);
        }

        return [
            'created' => true,
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'slug' => $client->slug,
                'primary_contact' => $contact ? [
                    'name' => $contact->name,
                    'email' => $contact->email,
                ] : null,
            ],
        ];
    }
}
