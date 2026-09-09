<?php

namespace App\Agents\Tools;

use Illuminate\Support\Facades\Http;

/**
 * Search the web for information.
 *
 * Uses a search API to find relevant information for research tasks.
 */
class WebSearchTool extends BaseTool
{
    public function category(): string
    {
        return 'general';
    }

    public function name(): string
    {
        return 'Web Search';
    }

    public function description(): string
    {
        return 'Search the web for information about companies, industries, news, and trends. Use for research when creating proposals, case studies, or content.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Search query',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['general', 'news', 'company'],
                    'description' => 'Type of search to perform',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max results (default 5)',
                ],
            ],
            'required' => ['query'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'query' => 'required|string|max:200',
            'type' => 'nullable|in:general,news,company',
            'limit' => 'nullable|integer|min:1|max:10',
        ];
    }

    public function execute(array $params): array
    {
        $query = $params['query'];
        $type = $params['type'] ?? 'general';
        $limit = $params['limit'] ?? 5;

        // Check if we have a search API configured
        $apiKey = config('services.serper.api_key') ?? config('services.serpapi.api_key');

        if ($apiKey && config('services.serper.api_key')) {
            return $this->searchWithSerper($query, $type, $limit, $apiKey);
        }

        if ($apiKey && config('services.serpapi.api_key')) {
            return $this->searchWithSerpApi($query, $type, $limit, $apiKey);
        }

        // Fallback: Return a message indicating search isn't configured
        return [
            'status' => 'not_configured',
            'message' => 'Web search API not configured. Please set SERPER_API_KEY or SERPAPI_API_KEY in environment.',
            'query' => $query,
            'results' => [],
        ];
    }

    protected function searchWithSerper(string $query, string $type, int $limit, string $apiKey): array
    {
        try {
            $endpoint = match ($type) {
                'news' => 'https://google.serper.dev/news',
                default => 'https://google.serper.dev/search',
            };

            $response = Http::withHeaders([
                'X-API-KEY' => $apiKey,
                'Content-Type' => 'application/json',
            ])->post($endpoint, [
                'q' => $query,
                'num' => $limit,
            ]);

            if ($response->failed()) {
                return [
                    'status' => 'error',
                    'message' => 'Search request failed',
                    'query' => $query,
                    'results' => [],
                ];
            }

            $data = $response->json();

            // Parse organic results
            $results = collect($data['organic'] ?? $data['news'] ?? [])
                ->take($limit)
                ->map(fn ($r) => [
                    'title' => $r['title'] ?? '',
                    'link' => $r['link'] ?? '',
                    'snippet' => $r['snippet'] ?? $r['description'] ?? '',
                    'date' => $r['date'] ?? null,
                ])
                ->toArray();

            return [
                'status' => 'success',
                'query' => $query,
                'type' => $type,
                'count' => count($results),
                'results' => $results,
                'knowledge_graph' => $data['knowledgeGraph'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'query' => $query,
                'results' => [],
            ];
        }
    }

    protected function searchWithSerpApi(string $query, string $type, int $limit, string $apiKey): array
    {
        try {
            $params = [
                'q' => $query,
                'api_key' => $apiKey,
                'num' => $limit,
            ];

            if ($type === 'news') {
                $params['tbm'] = 'nws';
            }

            $response = Http::get('https://serpapi.com/search', $params);

            if ($response->failed()) {
                return [
                    'status' => 'error',
                    'message' => 'Search request failed',
                    'query' => $query,
                    'results' => [],
                ];
            }

            $data = $response->json();

            $results = collect($data['organic_results'] ?? $data['news_results'] ?? [])
                ->take($limit)
                ->map(fn ($r) => [
                    'title' => $r['title'] ?? '',
                    'link' => $r['link'] ?? '',
                    'snippet' => $r['snippet'] ?? '',
                    'date' => $r['date'] ?? null,
                ])
                ->toArray();

            return [
                'status' => 'success',
                'query' => $query,
                'type' => $type,
                'count' => count($results),
                'results' => $results,
                'knowledge_graph' => $data['knowledge_graph'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'query' => $query,
                'results' => [],
            ];
        }
    }
}
