<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class DatabaseQueryTool extends Tool
{
    protected string $name = 'database-query';

    protected string $title = 'Database Query';

    protected string $description = 'Execute a read-only SQL query against the production database. Only SELECT, SHOW, DESCRIBE, and EXPLAIN queries are allowed.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'query' => 'required|string',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        $query = trim($request->get('query'));
        $limit = $request->get('limit', 100);

        // Validate query is read-only
        $queryLower = strtolower($query);
        $allowedPrefixes = ['select', 'show', 'describe', 'explain', 'with'];

        $startsWithAllowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($queryLower, $prefix)) {
                $startsWithAllowed = true;
                break;
            }
        }

        if (! $startsWithAllowed) {
            return Response::structured([
                'success' => false,
                'error' => 'Only read-only queries are allowed (SELECT, SHOW, DESCRIBE, EXPLAIN, WITH).',
            ]);
        }

        // Check for dangerous keywords that might be in subqueries
        $dangerousKeywords = ['insert', 'update', 'delete', 'drop', 'truncate', 'alter', 'create', 'grant', 'revoke'];
        foreach ($dangerousKeywords as $keyword) {
            // Check for keyword as a whole word (not part of column names)
            if (preg_match('/\b'.$keyword.'\b/i', $query)) {
                return Response::structured([
                    'success' => false,
                    'error' => "Query contains forbidden keyword: {$keyword}",
                ]);
            }
        }

        try {
            // Add LIMIT if not present for SELECT queries
            if (str_starts_with($queryLower, 'select') && ! preg_match('/\blimit\b/i', $query)) {
                $query = rtrim($query, ';')." LIMIT {$limit}";
            }

            $results = DB::select($query);

            return Response::structured([
                'success' => true,
                'row_count' => count($results),
                'results' => $results,
            ]);
        } catch (\Exception $e) {
            return Response::structured([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('SQL query to execute. Only SELECT, SHOW, DESCRIBE, and EXPLAIN are allowed.'),
            'limit' => $schema->integer()->description('Max rows to return (default: 100, max: 1000). Only applies to SELECT queries without LIMIT.'),
        ];
    }
}
