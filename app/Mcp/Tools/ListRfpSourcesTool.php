<?php

namespace App\Mcp\Tools;

use App\Models\RfpSource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ListRfpSourcesTool extends Tool
{
    protected string $name = 'list-rfp-sources';

    protected string $title = 'List RFP Sources';

    protected string $description = 'List all configured RFP discovery sources including email senders, RSS feeds, RFP boards, and web scrapers. Shows status, last checked time, and total opportunities found.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'active_only' => 'nullable|boolean',
            'type' => 'nullable|string|in:email_sender,rfp_board,rss_feed,web_scrape,government_api',
        ]);

        $query = RfpSource::query()->orderBy('is_active', 'desc')->orderByDesc('total_opportunities_found');

        if ($validated['active_only'] ?? false) {
            $query->where('is_active', true);
        }

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $sources = $query->get();

        return Response::structured([
            'sources' => $sources->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'is_active' => $s->is_active,
                'url' => $s->url,
                'check_frequency_minutes' => $s->check_frequency_minutes,
                'last_checked_at' => $s->last_checked_at?->diffForHumans(),
                'total_opportunities_found' => $s->total_opportunities_found ?? 0,
                'filters' => $s->filters,
                'config_summary' => $this->summarizeConfig($s),
            ])->toArray(),
            'total' => $sources->count(),
            'active' => $sources->where('is_active', true)->count(),
            'by_type' => $sources->groupBy('type')->map->count()->toArray(),
        ]);
    }

    private function summarizeConfig(RfpSource $source): string
    {
        $config = $source->config ?? [];

        return match ($source->type) {
            'email_sender' => implode(', ', $config['sender_emails'] ?? []),
            'rss_feed' => $config['feed_url'] ?? $source->url ?? '',
            'rfp_board', 'web_scrape' => $source->url ?? '',
            default => '',
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'active_only' => $schema->boolean()->description('Only return active sources (default: false, returns all)'),
            'type' => $schema->string()
                ->enum(['email_sender', 'rfp_board', 'rss_feed', 'web_scrape', 'government_api'])
                ->description('Filter by source type'),
        ];
    }
}
