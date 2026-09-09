<?php

namespace App\Mcp\Tools;

use App\Models\Email;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class SearchEmailsTool extends Tool
{
    protected string $name = 'search-emails';

    protected string $title = 'Search Emails';

    protected string $description = 'Search synced Gmail emails by sender, date range, subject, or body content. Useful for finding RFP teasers, client communications, or specific email threads.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'from' => 'nullable|string|max:255',
            'subject' => 'nullable|string|max:255',
            'body_contains' => 'nullable|string|max:255',
            'days' => 'nullable|integer|min:1|max:365',
            'since' => 'nullable|date',
            'client_id' => 'nullable|exists:clients,id',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $query = Email::query()->orderBy('received_at', 'desc');

        if ($from = $request->get('from')) {
            $query->where(function ($q) use ($from) {
                $q->where('from_address', 'like', "%{$from}%")
                    ->orWhere('from_name', 'like', "%{$from}%");
            });
        }

        if ($subject = $request->get('subject')) {
            $query->where('subject', 'like', "%{$subject}%");
        }

        if ($bodyContains = $request->get('body_contains')) {
            $query->where('body_text', 'like', "%{$bodyContains}%");
        }

        if ($days = $request->get('days')) {
            $query->where('received_at', '>=', now()->subDays($days));
        } elseif ($since = $request->get('since')) {
            $query->where('received_at', '>=', $since);
        }

        if ($clientId = $request->get('client_id')) {
            $query->where('client_id', $clientId);
        }

        $limit = $request->get('limit', 10);
        $emails = $query->limit($limit)->get();

        return Response::structured([
            'count' => $emails->count(),
            'emails' => $emails->map(fn (Email $e) => [
                'id' => $e->id,
                'from_address' => $e->from_address,
                'from_name' => $e->from_name,
                'subject' => $e->subject,
                'body_excerpt' => $e->body_text ? mb_substr($e->body_text, 0, 500) : null,
                'body_text' => $e->body_text,
                'received_at' => $e->received_at?->toIso8601String(),
                'received_ago' => $e->received_at?->diffForHumans(),
                'client_id' => $e->client_id,
                'has_attachments' => $e->hasAttachments(),
                'action_required' => $e->action_required,
                'sentiment_label' => $e->sentiment_label,
            ])->toArray(),
            'message' => $emails->count() > 0
                ? "Found {$emails->count()} email(s)."
                : 'No emails found matching your search.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Search by sender name or email address (partial match)'),
            'subject' => $schema->string()->description('Search by subject line (partial match)'),
            'body_contains' => $schema->string()->description('Search email body for keywords'),
            'days' => $schema->integer()->description('Only search emails from the last N days'),
            'since' => $schema->string()->format('date')->description('Only search emails since this date (YYYY-MM-DD)'),
            'client_id' => $schema->integer()->description('Filter by client ID'),
            'limit' => $schema->integer()->description('Maximum results to return (default: 10, max: 50)'),
        ];
    }
}
