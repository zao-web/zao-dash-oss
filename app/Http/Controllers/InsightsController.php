<?php

namespace App\Http\Controllers;

use App\Services\ProactiveInsightsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsightsController extends Controller
{
    public function __construct(
        protected ProactiveInsightsService $insightsService
    ) {}

    /**
     * Get proactive insights for the dashboard.
     */
    public function index(Request $request): JsonResponse
    {
        $limit = $request->input('limit', 6);
        $insights = $this->insightsService->getInsights($limit);

        return response()->json([
            'insights' => $insights,
            'count' => count($insights),
        ]);
    }
}
