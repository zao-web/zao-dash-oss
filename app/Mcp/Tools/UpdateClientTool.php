<?php

namespace App\Mcp\Tools;

use App\Models\Client;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateClientTool extends Tool
{
    protected string $name = 'update-client';

    protected string $title = 'Update Client';

    protected string $description = 'Update an existing client\'s information, including billing email, payment terms, and recurring invoice (retainer) settings. Omitted fields are left unchanged. Pass JSON null for billing_email, billing_cc_emails, recurring_invoice_description, or recurring_invoice_project_id to clear that column.';

    /**
     * @var list<string>
     */
    private const CLEARABLE_FIELDS = [
        'billing_email',
        'billing_cc_emails',
        'recurring_invoice_description',
        'recurring_invoice_project_id',
    ];

    private function updatableFieldRules(): array
    {
        return [
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'website' => 'nullable|url|max:255',
            'status' => 'nullable|in:active,inactive,prospect,churned,archived',
            'slack_channel' => 'nullable|string|max:255',
            'default_hourly_rate' => 'nullable|numeric|min:0',
            'health_score' => 'nullable|numeric|min:0|max:100',
            'billing_email' => 'nullable|email|max:255',
            'billing_cc_emails' => 'nullable|string|max:1000',
            'payment_terms' => 'nullable|string|in:Due on Receipt,Net 15,Net 30,Net 45,Net 60',
            'recurring_invoice_enabled' => 'nullable|boolean',
            'recurring_invoice_amount' => 'nullable|numeric|min:0',
            'recurring_invoice_day' => 'nullable|integer|min:1|max:28',
            'recurring_invoice_auto_send' => 'nullable|boolean',
            'recurring_invoice_description' => 'nullable|string|max:255',
            'recurring_invoice_project_id' => 'nullable|exists:projects,id',
            'recurring_invoice_in_advance' => 'nullable|boolean',
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate(array_merge([
            'id' => 'required|exists:clients,id',
        ], $this->updatableFieldRules()));

        $client = Client::findOrFail($request->get('id'));

        $updateData = collect($request->all())
            ->only(array_keys($this->updatableFieldRules()))
            ->filter(function (mixed $value, string $key): bool {
                if (in_array($key, self::CLEARABLE_FIELDS, true)) {
                    return true;
                }

                return ! is_null($value);
            })
            ->toArray();

        if ($updateData === []) {
            return Response::text('No fields to update provided.');
        }

        if (
            array_key_exists('recurring_invoice_project_id', $updateData)
            && $updateData['recurring_invoice_project_id'] !== null
        ) {
            $projectBelongsToClient = $client->projects()
                ->whereKey($updateData['recurring_invoice_project_id'])
                ->exists();

            if (! $projectBelongsToClient) {
                return Response::error('recurring_invoice_project_id must belong to this client.');
            }
        }

        $client->update($updateData);
        $client->refresh();

        return Response::structured([
            'id' => $client->id,
            'name' => $client->name,
            'slug' => $client->slug,
            'status' => $client->status,
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
            'updated_fields' => array_keys($updateData),
            'message' => "Client '{$client->name}' updated successfully.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required()->description('Client ID to update'),
            'name' => $schema->string()->description('New client name'),
            'description' => $schema->string()->description('New description'),
            'website' => $schema->string()->format('uri')->description('New website URL'),
            'status' => $schema->string()->enum(['active', 'inactive', 'prospect', 'churned', 'archived'])->description('New status'),
            'slack_channel' => $schema->string()->description('New Slack channel'),
            'default_hourly_rate' => $schema->number()->description('New hourly rate'),
            'health_score' => $schema->integer()->description('Health score (0-100)'),
            'billing_email' => $schema->string()->format('email')->nullable()->description('Billing recipient email used for invoices and auto-send. Pass JSON null to clear.'),
            'billing_cc_emails' => $schema->string()->nullable()->description('Billing CC addresses as a comma, semicolon, or newline separated string (max 1000 chars). Pass JSON null to clear.'),
            'payment_terms' => $schema->string()->enum(['Due on Receipt', 'Net 15', 'Net 30', 'Net 45', 'Net 60'])->description('Default invoice payment terms'),
            'recurring_invoice_enabled' => $schema->boolean()->description('Whether monthly recurring invoices are generated for this client'),
            'recurring_invoice_amount' => $schema->number()->description('Monthly recurring invoice amount (must be >= 0)'),
            'recurring_invoice_day' => $schema->integer()->description('Day of month to generate the recurring invoice (1-28)'),
            'recurring_invoice_auto_send' => $schema->boolean()->description('Whether generated recurring invoices are sent automatically'),
            'recurring_invoice_description' => $schema->string()->nullable()->description('Line-item description for the recurring invoice (max 255 chars). Supports {client_name} and {month_year} placeholders. Pass JSON null to clear.'),
            'recurring_invoice_project_id' => $schema->integer()->nullable()->description('Project ID to attach to generated recurring invoices (must belong to this client). Pass JSON null to clear.'),
            'recurring_invoice_in_advance' => $schema->boolean()->description('When true, the invoice generated on the billing day is labelled for the upcoming month'),
        ];
    }
}
