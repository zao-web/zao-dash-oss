<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\HarvestInvoice;
use App\Models\HarvestProject;
use App\Models\Project;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyzeIcpFromHistory extends Command
{
    protected $signature = 'icp:analyze-history 
                            {--min-revenue=5000 : Minimum client revenue to include}
                            {--json : Output as JSON for programmatic use}';

    protected $description = 'Analyze historical work to build ICPs based on actual clients, not aspirations';

    public function handle(): int
    {
        $minRevenue = (float) $this->option('min-revenue');
        $asJson = $this->option('json');

        if (! $asJson) {
            $this->info('Analyzing historical data to build ICPs...');
            $this->newLine();
        }

        $this->analyzeRevenue($asJson);
        $clientProfiles = $this->analyzeClientProfiles($minRevenue, $asJson);
        $this->analyzeTechStack($asJson);
        $this->analyzeProjectTypes($asJson);
        $this->analyzeEngagementTypes($asJson);
        $this->analyzeDealSizes($asJson);
        $icps = $this->generateIcpRecommendations($clientProfiles, $asJson);

        if ($asJson) {
            $this->line(json_encode([
                'icps' => $icps,
                'client_count' => count($clientProfiles),
                'generated_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT));
        }

        return Command::SUCCESS;
    }

    protected function analyzeRevenue(bool $asJson): void
    {
        if ($asJson) {
            return;
        }

        $this->info('=== REVENUE ANALYSIS ===');

        $invoices = HarvestInvoice::where('state', 'paid')->get();
        $totalRevenue = $invoices->sum('amount');
        $invoiceCount = $invoices->count();

        $this->table(['Metric', 'Value'], [
            ['Total Paid Revenue', '$'.number_format($totalRevenue, 0)],
            ['Total Invoices', $invoiceCount],
            ['Avg Invoice Size', '$'.number_format($invoiceCount > 0 ? $totalRevenue / $invoiceCount : 0, 0)],
        ]);

        $byYear = $invoices->groupBy(fn ($i) => $i->paid_at?->format('Y'))
            ->map(fn ($group) => $group->sum('amount'))
            ->sortKeys();

        $this->info('Revenue by Year:');
        foreach ($byYear as $year => $amount) {
            $this->line("  {$year}: \$".number_format($amount, 0));
        }
        $this->newLine();
    }

    protected function analyzeClientProfiles(float $minRevenue, bool $asJson): array
    {
        if (! $asJson) {
            $this->info('=== TOP CLIENTS (by revenue) ===');
        }

        $clients = Client::with(['invoices', 'projects', 'harvestProjects'])->get();

        $profiles = [];
        foreach ($clients as $client) {
            $revenue = $client->invoices->where('state', 'paid')->sum('amount');
            if ($revenue < $minRevenue) {
                continue;
            }

            $profiles[] = [
                'id' => $client->id,
                'name' => $client->name,
                'industry' => $client->industry,
                'revenue' => $revenue,
                'project_count' => $client->projects->count(),
                'has_retainer' => $client->harvestProjects->where('budget_is_monthly', true)->isNotEmpty(),
                'retainer_value' => $client->harvestProjects->where('budget_is_monthly', true)->sum('budget'),
                'tech_stack' => $client->projects->pluck('tech_stack')->flatten()->filter()->unique()->values()->toArray(),
                'project_types' => $client->projects->pluck('type')->filter()->unique()->values()->toArray(),
            ];
        }

        usort($profiles, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        if (! $asJson) {
            $tableData = array_map(fn ($p) => [
                $p['name'],
                $p['industry'] ?? 'Unknown',
                '$'.number_format($p['revenue'], 0),
                $p['project_count'],
                $p['has_retainer'] ? '$'.number_format($p['retainer_value'], 0).'/mo' : 'No',
            ], array_slice($profiles, 0, 20));

            $this->table(['Client', 'Industry', 'Revenue', 'Projects', 'Retainer'], $tableData);
            $this->newLine();
        }

        return $profiles;
    }

    protected function analyzeTechStack(bool $asJson): void
    {
        if ($asJson) {
            return;
        }

        $this->info('=== TECH STACK ANALYSIS ===');

        $techCounts = Project::whereNotNull('tech_stack')
            ->pluck('tech_stack')
            ->flatten()
            ->filter()
            ->countBy()
            ->sortDesc()
            ->take(15);

        if ($techCounts->isEmpty()) {
            $techCounts = HarvestProject::whereNotNull('notes')
                ->pluck('notes')
                ->map(function ($notes) {
                    $techs = [];
                    $keywords = ['WordPress', 'Laravel', 'PHP', 'React', 'Vue', 'WooCommerce', 'Shopify', 'JavaScript', 'TypeScript', 'Node', 'Python', 'AWS', 'Gutenberg', 'ACF'];
                    foreach ($keywords as $kw) {
                        if (stripos($notes, $kw) !== false) {
                            $techs[] = $kw;
                        }
                    }

                    return $techs;
                })
                ->flatten()
                ->countBy()
                ->sortDesc();
        }

        foreach ($techCounts as $tech => $count) {
            $this->line("  {$tech}: {$count} projects");
        }
        $this->newLine();
    }

    protected function analyzeProjectTypes(bool $asJson): void
    {
        if ($asJson) {
            return;
        }

        $this->info('=== PROJECT TYPE ANALYSIS ===');

        $types = Project::whereNotNull('type')
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->orderByDesc('count')
            ->get();

        if ($types->isEmpty()) {
            $projects = HarvestProject::pluck('name');
            $inferred = [
                'Development' => 0,
                'Maintenance' => 0,
                'Design' => 0,
                'Consulting' => 0,
                'Migration' => 0,
                'Support' => 0,
            ];

            foreach ($projects as $name) {
                $name = strtolower($name);
                if (str_contains($name, 'maint') || str_contains($name, 'retainer') || str_contains($name, 'support')) {
                    $inferred['Maintenance']++;
                } elseif (str_contains($name, 'design')) {
                    $inferred['Design']++;
                } elseif (str_contains($name, 'consult')) {
                    $inferred['Consulting']++;
                } elseif (str_contains($name, 'migrat')) {
                    $inferred['Migration']++;
                } else {
                    $inferred['Development']++;
                }
            }

            arsort($inferred);
            foreach ($inferred as $type => $count) {
                if ($count > 0) {
                    $this->line("  {$type}: {$count}");
                }
            }
        } else {
            foreach ($types as $t) {
                $this->line("  {$t->type}: {$t->count}");
            }
        }
        $this->newLine();
    }

    protected function analyzeEngagementTypes(bool $asJson): void
    {
        if ($asJson) {
            return;
        }

        $this->info('=== ENGAGEMENT TYPE ANALYSIS ===');

        $projects = HarvestProject::where('is_active', true)->get();
        $retainers = $projects->where('budget_is_monthly', true);
        $fixed = $projects->where('budget_is_monthly', false)->where('budget', '>', 0);
        $hourly = $projects->filter(fn ($p) => $p->budget == 0 || $p->budget === null);

        $retainerRevenue = $retainers->sum('budget');

        $this->table(['Type', 'Count', 'Value'], [
            ['Monthly Retainers', $retainers->count(), '$'.number_format($retainerRevenue, 0).'/mo'],
            ['Fixed Budget', $fixed->count(), '$'.number_format($fixed->sum('budget'), 0).' total'],
            ['Hourly/T&M', $hourly->count(), 'Variable'],
        ]);

        if ($retainers->count() > 0) {
            $this->info('Retainer Breakdown:');
            foreach ($retainers->sortByDesc('budget') as $r) {
                $this->line("  {$r->name}: \$".number_format($r->budget, 0).'/mo');
            }
        }
        $this->newLine();
    }

    protected function analyzeDealSizes(bool $asJson): void
    {
        if ($asJson) {
            return;
        }

        $this->info('=== DEAL SIZE ANALYSIS ===');

        $invoices = HarvestInvoice::where('state', 'paid')
            ->whereNotNull('amount')
            ->where('amount', '>', 0)
            ->get();

        $ranges = [
            'Under $1k' => $invoices->where('amount', '<', 1000)->count(),
            '$1k - $5k' => $invoices->whereBetween('amount', [1000, 5000])->count(),
            '$5k - $10k' => $invoices->whereBetween('amount', [5000, 10000])->count(),
            '$10k - $25k' => $invoices->whereBetween('amount', [10000, 25000])->count(),
            '$25k - $50k' => $invoices->whereBetween('amount', [25000, 50000])->count(),
            'Over $50k' => $invoices->where('amount', '>', 50000)->count(),
        ];

        foreach ($ranges as $range => $count) {
            $pct = $invoices->count() > 0 ? round(($count / $invoices->count()) * 100, 1) : 0;
            $this->line("  {$range}: {$count} ({$pct}%)");
        }
        $this->newLine();
    }

    protected function generateIcpRecommendations(array $clientProfiles, bool $asJson): array
    {
        if (! $asJson) {
            $this->info('=== ICP RECOMMENDATIONS ===');
            $this->newLine();
        }

        $icps = [];

        $byIndustry = collect($clientProfiles)
            ->groupBy('industry')
            ->filter(fn ($group, $key) => $key !== null && $group->count() >= 2);

        foreach ($byIndustry as $industry => $clients) {
            $totalRevenue = $clients->sum('revenue');
            $avgRevenue = $clients->avg('revenue');
            $retainerClients = $clients->where('has_retainer', true)->count();
            $allTech = $clients->pluck('tech_stack')->flatten()->filter()->countBy()->sortDesc()->keys()->take(3)->toArray();

            $icps[] = [
                'name' => $industry.' Companies',
                'slug' => Str::slug($industry),
                'industry' => $industry,
                'client_count' => $clients->count(),
                'total_revenue' => $totalRevenue,
                'avg_deal' => $avgRevenue,
                'retainer_rate' => $clients->count() > 0 ? round(($retainerClients / $clients->count()) * 100) : 0,
                'tech_stack' => $allTech,
                'example_clients' => $clients->take(3)->pluck('name')->toArray(),
            ];
        }

        usort($icps, fn ($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

        if (! $asJson) {
            foreach (array_slice($icps, 0, 5) as $i => $icp) {
                $this->warn('ICP #'.($i + 1).": {$icp['name']}");
                $this->line("  Clients: {$icp['client_count']}");
                $this->line('  Total Revenue: $'.number_format($icp['total_revenue'], 0));
                $this->line('  Avg Deal: $'.number_format($icp['avg_deal'], 0));
                $this->line("  Retainer Rate: {$icp['retainer_rate']}%");
                $this->line('  Tech Stack: '.implode(', ', $icp['tech_stack']));
                $this->line('  Examples: '.implode(', ', $icp['example_clients']));
                $this->newLine();
            }
        }

        $retainerClients = collect($clientProfiles)->where('has_retainer', true);
        if ($retainerClients->count() >= 2) {
            $avgRetainer = $retainerClients->avg('retainer_value');
            $icps[] = [
                'name' => 'Retainer-Ready Companies',
                'slug' => 'retainer-ready',
                'description' => 'Companies who value ongoing partnerships over one-off projects',
                'client_count' => $retainerClients->count(),
                'total_revenue' => $retainerClients->sum('revenue'),
                'avg_retainer' => $avgRetainer,
                'signals' => [
                    'Existing WordPress site needing updates',
                    'In-house team lacking bandwidth',
                    'History of repeat projects',
                    'Budget for ongoing development',
                ],
            ];

            if (! $asJson) {
                $this->warn('ICP: Retainer-Ready Companies');
                $this->line("  Clients on retainer: {$retainerClients->count()}");
                $this->line('  Avg retainer value: $'.number_format($avgRetainer, 0).'/mo');
                $this->line('  Total retainer revenue: $'.number_format($retainerClients->sum('retainer_value'), 0).'/mo');
                $this->newLine();
            }
        }

        return $icps;
    }
}
