<?php

namespace App\Agents\Tools;

use App\Models\Client;
use App\Models\HarvestInvoice;
use App\Models\HarvestProject;
use App\Models\Lead;

class AnalyzeClosedDealsTool extends BaseTool
{
    public function category(): string
    {
        return 'analysis';
    }

    public function name(): string
    {
        return 'Analyze Closed Deals';
    }

    public function description(): string
    {
        return 'Analyze historical client data to identify patterns in successful deals. Returns insights on client types, deal sizes, industries, and revenue patterns useful for ICP definition.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'analysis_type' => [
                    'type' => 'string',
                    'enum' => ['full', 'revenue', 'clients', 'projects', 'leads'],
                    'description' => 'Type of analysis: full (all data), revenue (invoice patterns), clients (client profiles), projects (project types), leads (conversion patterns)',
                ],
                'time_period' => [
                    'type' => 'string',
                    'enum' => ['all', 'year', 'quarter', '6months'],
                    'description' => 'Time period to analyze (default: all)',
                ],
                'min_revenue' => [
                    'type' => 'number',
                    'description' => 'Minimum revenue threshold to include in analysis',
                ],
            ],
            'required' => [],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'analysis_type' => 'nullable|in:full,revenue,clients,projects,leads',
            'time_period' => 'nullable|in:all,year,quarter,6months',
            'min_revenue' => 'nullable|numeric|min:0',
        ];
    }

    public function execute(array $params): array
    {
        $analysisType = $params['analysis_type'] ?? 'full';
        $timePeriod = $params['time_period'] ?? 'all';
        $minRevenue = $params['min_revenue'] ?? 0;

        $dateFilter = $this->getDateFilter($timePeriod);

        $result = [
            'analysis_type' => $analysisType,
            'time_period' => $timePeriod,
            'generated_at' => now()->toDateTimeString(),
        ];

        switch ($analysisType) {
            case 'revenue':
                $result['revenue_analysis'] = $this->analyzeRevenue($dateFilter, $minRevenue);
                break;
            case 'clients':
                $result['client_analysis'] = $this->analyzeClients($minRevenue);
                break;
            case 'projects':
                $result['project_analysis'] = $this->analyzeProjects();
                break;
            case 'leads':
                $result['lead_analysis'] = $this->analyzeLeads($dateFilter);
                break;
            case 'full':
            default:
                $result['revenue_analysis'] = $this->analyzeRevenue($dateFilter, $minRevenue);
                $result['client_analysis'] = $this->analyzeClients($minRevenue);
                $result['project_analysis'] = $this->analyzeProjects();
                $result['lead_analysis'] = $this->analyzeLeads($dateFilter);
                $result['summary'] = $this->generateSummary($result);
                break;
        }

        return $result;
    }

    private function getDateFilter(string $period): ?string
    {
        return match ($period) {
            'year' => now()->subYear()->toDateString(),
            'quarter' => now()->subQuarter()->toDateString(),
            '6months' => now()->subMonths(6)->toDateString(),
            default => null,
        };
    }

    private function analyzeRevenue(?string $dateFilter, float $minRevenue): array
    {
        $query = HarvestInvoice::query()->where('state', 'paid');

        if ($dateFilter) {
            $query->where('paid_at', '>=', $dateFilter);
        }

        $invoices = $query->get();

        $totalRevenue = $invoices->sum('amount');
        $avgDealSize = $invoices->count() > 0 ? $invoices->avg('amount') : 0;

        $revenueByClient = $invoices
            ->filter(fn ($invoice) => ! empty($invoice->client_id))
            ->groupBy('client_id')
            ->map(function ($clientInvoices) {
                return [
                    'total' => $clientInvoices->sum('amount'),
                    'count' => $clientInvoices->count(),
                    'avg' => $clientInvoices->avg('amount'),
                ];
            })->sortByDesc('total')->take(10);

        $topClients = [];
        foreach ($revenueByClient as $clientId => $data) {
            if (empty($clientId)) {
                continue;
            }
            $client = Client::find($clientId);
            if ($client && $data['total'] >= $minRevenue) {
                $topClients[] = [
                    'client' => $client->name,
                    'total_revenue' => round($data['total'], 2),
                    'invoice_count' => $data['count'],
                    'avg_invoice' => round($data['avg'], 2),
                ];
            }
        }

        $monthlyRevenue = $invoices->groupBy(fn ($i) => $i->paid_at?->format('Y-m'))
            ->map(fn ($group) => round($group->sum('amount'), 2))
            ->sortKeys()
            ->take(-12);

        return [
            'total_revenue' => round($totalRevenue, 2),
            'invoice_count' => $invoices->count(),
            'avg_deal_size' => round($avgDealSize, 2),
            'top_clients_by_revenue' => $topClients,
            'monthly_trend' => $monthlyRevenue->toArray(),
        ];
    }

    private function analyzeClients(float $minRevenue): array
    {
        $clients = Client::with(['projects', 'invoices', 'harvestProjects'])->get();

        $clientProfiles = [];
        foreach ($clients as $client) {
            $revenue = $client->invoices->where('state', 'paid')->sum('amount');

            if ($revenue < $minRevenue) {
                continue;
            }

            $clientProfiles[] = [
                'name' => $client->name,
                'status' => $client->status,
                'total_revenue' => round($revenue, 2),
                'project_count' => $client->projects->count(),
                'active_projects' => $client->projects->where('status', 'active')->count(),
                'has_retainer' => $client->harvestProjects->where('budget_is_monthly', true)->isNotEmpty(),
                'avg_project_value' => $client->projects->count() > 0
                    ? round($revenue / $client->projects->count(), 2)
                    : 0,
            ];
        }

        usort($clientProfiles, fn ($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);
        $topClients = array_slice($clientProfiles, 0, 15);

        $retainerClients = array_filter($clientProfiles, fn ($c) => $c['has_retainer']);
        $projectClients = array_filter($clientProfiles, fn ($c) => ! $c['has_retainer']);

        return [
            'total_clients' => count($clientProfiles),
            'clients_with_retainers' => count($retainerClients),
            'project_based_clients' => count($projectClients),
            'retainer_revenue' => round(array_sum(array_column($retainerClients, 'total_revenue')), 2),
            'project_revenue' => round(array_sum(array_column($projectClients, 'total_revenue')), 2),
            'top_clients' => $topClients,
        ];
    }

    private function analyzeProjects(): array
    {
        $projects = HarvestProject::with('client')->get();

        $retainerProjects = $projects->where('budget_is_monthly', true);
        $fixedProjects = $projects->where('budget_is_monthly', false)->where('budget', '>', 0);

        $billableProjects = $projects->where('is_billable', true);
        $avgHourlyRate = $billableProjects->avg('hourly_rate') ?? 0;

        $budgetRanges = [
            'under_5k' => $fixedProjects->where('budget', '<', 5000)->count(),
            '5k_10k' => $fixedProjects->whereBetween('budget', [5000, 10000])->count(),
            '10k_25k' => $fixedProjects->whereBetween('budget', [10000, 25000])->count(),
            '25k_50k' => $fixedProjects->whereBetween('budget', [25000, 50000])->count(),
            'over_50k' => $fixedProjects->where('budget', '>', 50000)->count(),
        ];

        $retainerValues = [
            'under_2k' => $retainerProjects->where('budget', '<', 2000)->count(),
            '2k_5k' => $retainerProjects->whereBetween('budget', [2000, 5000])->count(),
            '5k_10k' => $retainerProjects->whereBetween('budget', [5000, 10000])->count(),
            'over_10k' => $retainerProjects->where('budget', '>', 10000)->count(),
        ];

        return [
            'total_projects' => $projects->count(),
            'active_projects' => $projects->where('is_active', true)->count(),
            'retainer_projects' => $retainerProjects->count(),
            'fixed_bid_projects' => $fixedProjects->count(),
            'avg_hourly_rate' => round($avgHourlyRate, 2),
            'monthly_retainer_value' => round($retainerProjects->sum('budget'), 2),
            'fixed_project_budgets' => $budgetRanges,
            'retainer_value_distribution' => $retainerValues,
        ];
    }

    private function analyzeLeads(?string $dateFilter): array
    {
        $query = Lead::query();

        if ($dateFilter) {
            $query->where('created_at', '>=', $dateFilter);
        }

        $leads = $query->get();

        $wonLeads = $leads->where('status', 'won');
        $lostLeads = $leads->where('status', 'lost');

        $conversionRate = $leads->count() > 0
            ? round(($wonLeads->count() / $leads->count()) * 100, 1)
            : 0;

        $avgWonDeal = $wonLeads->avg('deal_value') ?? 0;
        $avgLostDeal = $lostLeads->avg('deal_value') ?? 0;

        $bySource = $leads->groupBy('source')->map(fn ($group) => [
            'total' => $group->count(),
            'won' => $group->where('status', 'won')->count(),
            'conversion_rate' => $group->count() > 0
                ? round(($group->where('status', 'won')->count() / $group->count()) * 100, 1)
                : 0,
        ]);

        return [
            'total_leads' => $leads->count(),
            'won_deals' => $wonLeads->count(),
            'lost_deals' => $lostLeads->count(),
            'conversion_rate' => $conversionRate,
            'avg_won_deal_value' => round($avgWonDeal, 2),
            'avg_lost_deal_value' => round($avgLostDeal, 2),
            'total_won_revenue' => round($wonLeads->sum('deal_value'), 2),
            'by_source' => $bySource->toArray(),
        ];
    }

    private function generateSummary(array $data): array
    {
        $insights = [];

        if (isset($data['revenue_analysis'])) {
            $rev = $data['revenue_analysis'];
            $insights[] = "Average deal size: \${$rev['avg_deal_size']}";

            if (count($rev['top_clients_by_revenue']) > 0) {
                $topClient = $rev['top_clients_by_revenue'][0];
                $insights[] = "Top client ({$topClient['client']}) accounts for \${$topClient['total_revenue']} in revenue";
            }
        }

        if (isset($data['client_analysis'])) {
            $cli = $data['client_analysis'];
            $retainerPercent = $cli['total_clients'] > 0
                ? round(($cli['clients_with_retainers'] / $cli['total_clients']) * 100, 1)
                : 0;
            $insights[] = "{$retainerPercent}% of clients are on retainers";

            $retainerRevPercent = ($cli['retainer_revenue'] + $cli['project_revenue']) > 0
                ? round(($cli['retainer_revenue'] / ($cli['retainer_revenue'] + $cli['project_revenue'])) * 100, 1)
                : 0;
            $insights[] = "Retainers generate {$retainerRevPercent}% of revenue";
        }

        if (isset($data['lead_analysis'])) {
            $lead = $data['lead_analysis'];
            $insights[] = "Lead conversion rate: {$lead['conversion_rate']}%";
            $insights[] = "Average won deal: \${$lead['avg_won_deal_value']}";
        }

        if (isset($data['project_analysis'])) {
            $proj = $data['project_analysis'];
            $insights[] = "Average hourly rate: \${$proj['avg_hourly_rate']}";
            $insights[] = "Current monthly retainer value: \${$proj['monthly_retainer_value']}";
        }

        return [
            'key_insights' => $insights,
            'recommendations' => [
                'Focus on clients with retainer potential based on project history',
                'Target deals in the average deal size range for higher conversion',
                'Prioritize lead sources with highest conversion rates',
            ],
        ];
    }
}
