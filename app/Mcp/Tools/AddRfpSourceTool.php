<?php

namespace App\Mcp\Tools;

use App\Models\RfpSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class AddRfpSourceTool extends Tool
{
    protected string $name = 'add-rfp-source';

    protected string $title = 'Add RFP Source';

    protected string $description = 'Add a new RFP discovery source to the pipeline. Supports email senders, RSS feeds, RFP board URLs, and web scrape targets.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string|in:email_sender,rfp_board,rss_feed,web_scrape,government_api',
            'url' => 'nullable|url',
            'sender_emails' => 'nullable|array',
            'sender_emails.*' => 'email',
            'check_frequency_minutes' => 'nullable|integer|min:15|max:1440',
            'filters' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $config = [];
        if (! empty($validated['sender_emails'])) {
            $config['sender_emails'] = $validated['sender_emails'];
        }

        $source = RfpSource::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(4),
            'type' => $validated['type'],
            'url' => $validated['url'] ?? null,
            'config' => $config,
            'filters' => $validated['filters'] ?? [],
            'check_frequency_minutes' => $validated['check_frequency_minutes'] ?? 240,
            'is_active' => $validated['is_active'] ?? true,
            'total_opportunities_found' => 0,
        ]);

        return Response::structured([
            'id' => $source->id,
            'name' => $source->name,
            'type' => $source->type,
            'is_active' => $source->is_active,
            'check_frequency_minutes' => $source->check_frequency_minutes,
            'message' => "Source '{$source->name}' added successfully. It will be checked every {$source->check_frequency_minutes} minutes.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Human-readable name for the source (e.g. "Municibid RFP Board")'),
            'type' => $schema->string()->required()
                ->enum(['email_sender', 'rfp_board', 'rss_feed', 'web_scrape', 'government_api'])
                ->description('Type of source'),
            'url' => $schema->string()->format('uri')->description('URL for rfp_board, rss_feed, or web_scrape types'),
            'sender_emails' => $schema->array()->description('List of sender email addresses for email_sender type'),
            'check_frequency_minutes' => $schema->integer()->description('How often to check this source (default: 240 = 4 hours, min: 15)'),
            'filters' => $schema->object()->description('Optional keyword/budget filters: {keywords: [], min_budget: 0}'),
            'is_active' => $schema->boolean()->description('Whether to start scanning immediately (default: true)'),
        ];
    }
}
