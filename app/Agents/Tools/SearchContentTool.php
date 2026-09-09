<?php

namespace App\Agents\Tools;

use App\Models\ContentSuggestion;
use App\Models\WordPressSite;

/**
 * Search for content suggestions and WordPress posts.
 */
class SearchContentTool extends BaseTool
{
    public function category(): string
    {
        return 'data';
    }

    public function name(): string
    {
        return 'Search Content';
    }

    public function description(): string
    {
        return 'Search for content suggestions, drafts, and scheduled posts. Use for content calendar management.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search term to find in title or content',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending', 'approved', 'published', 'rejected'],
                    'description' => 'Filter by content status',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['blog_post', 'case_study', 'landing_page', 'social'],
                    'description' => 'Filter by content type',
                ],
                'site_id' => [
                    'type' => 'integer',
                    'description' => 'Filter by WordPress site ID',
                ],
                'scheduled_after' => [
                    'type' => 'string',
                    'description' => 'Only content scheduled after this date (YYYY-MM-DD)',
                ],
                'scheduled_before' => [
                    'type' => 'string',
                    'description' => 'Only content scheduled before this date (YYYY-MM-DD)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results (default 10)',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'query' => 'nullable|string|max:100',
            'status' => 'nullable|in:pending,approved,published,rejected',
            'type' => 'nullable|in:blog_post,case_study,landing_page,social',
            'site_id' => 'nullable|integer|exists:wordpress_sites,id',
            'scheduled_after' => 'nullable|date',
            'scheduled_before' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:200',
        ];
    }

    public function execute(array $params): array
    {
        $query = ContentSuggestion::with('wordPressSite');

        if (! empty($params['query'])) {
            $search = $params['query'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('content', 'like', "%{$search}%")
                    ->orWhere('summary', 'like', "%{$search}%");
            });
        }

        if (! empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (! empty($params['type'])) {
            $query->where('content_type', $params['type']);
        }

        if (! empty($params['site_id'])) {
            $query->where('wordpress_site_id', $params['site_id']);
        }

        if (! empty($params['scheduled_after'])) {
            $query->where('scheduled_at', '>=', $params['scheduled_after']);
        }

        if (! empty($params['scheduled_before'])) {
            $query->where('scheduled_at', '<=', $params['scheduled_before']);
        }

        $limit = $params['limit'] ?? 10;
        $suggestions = $query->orderBy('created_at', 'desc')->limit($limit)->get();

        // Also get available WordPress sites for context
        $sites = WordPressSite::select('id', 'name', 'url')->get();

        return [
            'count' => $suggestions->count(),
            'content' => $suggestions->map(fn ($s) => [
                'id' => $s->id,
                'title' => $s->title,
                'content_type' => $s->content_type,
                'status' => $s->status,
                'summary' => $s->summary,
                'site_name' => $s->wordPressSite?->name,
                'site_id' => $s->wordpress_site_id,
                'scheduled_at' => $s->scheduled_at?->toDateTimeString(),
                'published_at' => $s->published_at?->toDateTimeString(),
                'created_at' => $s->created_at->toDateTimeString(),
                'meta_title' => $s->meta_title,
                'meta_description' => $s->meta_description,
            ])->toArray(),
            'available_sites' => $sites->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'url' => $s->url,
            ])->toArray(),
        ];
    }
}
