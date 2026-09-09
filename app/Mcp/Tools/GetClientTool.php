<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetClientTool extends Tool
{
    protected string $name = 'get-client';

    protected string $title = 'Get Client Details';

    protected string $description = 'Get detailed information about a specific client including contacts, projects, billing, recurring invoice settings, and recent activity.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => 'required_without:slug',
            'slug' => 'required_without:id',
        ]);

        $client = $request->get('id')
            ? Client::with(['contacts', 'projects', 'notes.user'])->findOrFail($request->get('id'))
            : Client::with(['contacts', 'projects', 'notes.user'])->where('slug', $request->get('slug'))->firstOrFail();

        return Response::structured([
            'id' => $client->id,
            'name' => $client->name,
            'slug' => $client->slug,
            'description' => $client->description,
            'status' => $client->status,
            'health_score' => $client->health_score,
            'website' => $client->website,
            'slack_channel' => $client->slack_channel,
            'default_hourly_rate' => $client->default_hourly_rate,
            'billing_email' => $client->billing_email,
            'billing_cc_emails' => $client->billing_cc_emails,
            'payment_terms' => $client->payment_terms,
            'recurring_invoice_enabled' => (bool) $client->recurring_invoice_enabled,
            'recurring_invoice_amount' => $client->recurring_invoice_amount !== null
                ? (float) $client->recurring_invoice_amount
                : null,
            'recurring_invoice_day' => $client->recurring_invoice_day,
            'recurring_invoice_auto_send' => (bool) $client->recurring_invoice_auto_send,
            'recurring_invoice_description' => $client->recurring_invoice_description,
            'recurring_invoice_project_id' => $client->recurring_invoice_project_id,
            'recurring_invoice_in_advance' => (bool) $client->recurring_invoice_in_advance,
            'contacts' => $client->contacts->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'phone' => $c->phone,
                'role' => $c->role,
                'is_primary' => $c->is_primary,
            ]),
            'projects' => $client->projects->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'slug' => $p->slug,
                'status' => $p->status,
                'type' => $p->type,
                'budget' => $p->budget,
            ]),
            'recent_notes' => $client->notes->take(5)->map(fn ($n) => [
                'content' => $n->content,
                'user' => $n->user->name,
                'created_at' => $n->created_at->diffForHumans(),
            ]),
            'created_at' => $client->created_at->toIso8601String(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Client ID'),
            'slug' => $schema->string()->description('Client slug (alternative to ID)'),
        ];
    }
}
