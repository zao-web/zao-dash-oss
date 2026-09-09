<?php

namespace App\Http\Controllers\Api;

use App\Agents\ToolRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ToolController extends Controller
{
    public function __construct(
        protected ToolRegistry $registry
    ) {}

    /**
     * List all available tools with metadata.
     */
    public function index(Request $request): JsonResponse
    {
        $tools = collect($this->registry->all())
            ->map(fn ($tool) => $tool->toArray())
            ->values();

        // Group by category if requested
        if ($request->boolean('grouped')) {
            $grouped = $tools->groupBy('category')->map(fn ($items) => $items->values());

            return response()->json([
                'tools' => $grouped,
                'categories' => $grouped->keys()->sort()->values(),
            ]);
        }

        // Get unique categories
        $categories = $tools->pluck('category')->unique()->sort()->values();

        return response()->json([
            'tools' => $tools,
            'categories' => $categories,
            'count' => $tools->count(),
        ]);
    }

    /**
     * Get a single tool by ID.
     */
    public function show(string $id): JsonResponse
    {
        $tool = $this->registry->get($id);

        if (! $tool) {
            return response()->json(['error' => 'Tool not found'], 404);
        }

        return response()->json(['tool' => $tool->toArray()]);
    }
}
