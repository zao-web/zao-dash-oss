<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\PersonalFinance\RevenueOptimizationService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetClientProfitabilityTool extends Tool
{
    protected string $name = 'get-client-profitability';

    protected string $title = 'Get Client Profitability';

    protected string $description = 'Get client profitability analysis showing revenue, hours, cost, profit margin, and effective rate per client. Includes utilization rate and revenue gap analysis.';

    public function __construct(
        protected RevenueOptimizationService $revenueService,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = Auth::user() ?? User::where('role', 'admin')->first();

        if (! $user) {
            return Response::structured(['error' => 'No user context available.']);
        }

        $period = $request->get('period');
        $profitability = $this->revenueService->getClientProfitability($period);
        $utilization = $this->revenueService->getUtilizationRate($period);

        return Response::structured([
            'client_profitability' => $profitability,
            'utilization' => $utilization,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'period' => $schema->string()
                ->description('Time period filter: monthly, quarterly, or yearly (default: all time)'),
        ];
    }
}
