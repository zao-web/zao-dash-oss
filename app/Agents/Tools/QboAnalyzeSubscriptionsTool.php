<?php

namespace App\Agents\Tools;

use App\Models\QuickBooksConnection;
use App\Services\QuickBooks\QuickBooksApiService;
use Carbon\Carbon;

/**
 * Analyze recurring subscriptions and suggest build-vs-buy opportunities.
 */
class QboAnalyzeSubscriptionsTool extends BaseTool
{
    protected QuickBooksApiService $qboService;

    public function category(): string
    {
        return 'quickbooks';
    }

    // Known SaaS/subscription vendors that could potentially be self-hosted
    protected array $buildableServices = [
        'loom' => [
            'category' => 'Video Recording & Sharing',
            'alternative' => 'Self-hosted video service with Chrome extension',
            'complexity' => 'medium',
            'savings_potential' => 'high',
        ],
        'calendly' => [
            'category' => 'Scheduling',
            'alternative' => 'Cal.com self-hosted or custom booking system',
            'complexity' => 'low',
            'savings_potential' => 'medium',
        ],
        'typeform' => [
            'category' => 'Forms & Surveys',
            'alternative' => 'Custom form builder or Formbricks self-hosted',
            'complexity' => 'low',
            'savings_potential' => 'medium',
        ],
        'intercom' => [
            'category' => 'Customer Support Chat',
            'alternative' => 'Chatwoot self-hosted',
            'complexity' => 'medium',
            'savings_potential' => 'high',
        ],
        'zendesk' => [
            'category' => 'Help Desk',
            'alternative' => 'Custom ticketing system or self-hosted alternative',
            'complexity' => 'high',
            'savings_potential' => 'high',
        ],
        'mailchimp' => [
            'category' => 'Email Marketing',
            'alternative' => 'Listmonk self-hosted or custom email system',
            'complexity' => 'medium',
            'savings_potential' => 'high',
        ],
        'convertkit' => [
            'category' => 'Email Marketing',
            'alternative' => 'Listmonk self-hosted or custom email system',
            'complexity' => 'medium',
            'savings_potential' => 'high',
        ],
        'hubspot' => [
            'category' => 'CRM',
            'alternative' => 'Custom CRM (already have parts in Zao Dash)',
            'complexity' => 'high',
            'savings_potential' => 'very high',
        ],
        'notion' => [
            'category' => 'Documentation & Wiki',
            'alternative' => 'Outline self-hosted or custom docs system',
            'complexity' => 'high',
            'savings_potential' => 'medium',
        ],
        'airtable' => [
            'category' => 'Database/Spreadsheet',
            'alternative' => 'NocoDB self-hosted or custom database UI',
            'complexity' => 'high',
            'savings_potential' => 'medium',
        ],
        'zapier' => [
            'category' => 'Automation',
            'alternative' => 'n8n self-hosted or custom webhooks/jobs',
            'complexity' => 'medium',
            'savings_potential' => 'high',
        ],
        'make' => [
            'category' => 'Automation',
            'alternative' => 'n8n self-hosted or custom webhooks/jobs',
            'complexity' => 'medium',
            'savings_potential' => 'high',
        ],
        'figma' => [
            'category' => 'Design',
            'alternative' => 'Penpot self-hosted (limited)',
            'complexity' => 'very high',
            'savings_potential' => 'low',
        ],
        'canva' => [
            'category' => 'Design',
            'alternative' => 'Limited self-hosted options',
            'complexity' => 'very high',
            'savings_potential' => 'low',
        ],
        'slack' => [
            'category' => 'Team Communication',
            'alternative' => 'Mattermost self-hosted',
            'complexity' => 'medium',
            'savings_potential' => 'high',
        ],
        'asana' => [
            'category' => 'Project Management',
            'alternative' => 'Custom PM in Zao Dash or Plane self-hosted',
            'complexity' => 'medium',
            'savings_potential' => 'medium',
        ],
        'monday' => [
            'category' => 'Project Management',
            'alternative' => 'Custom PM in Zao Dash or Plane self-hosted',
            'complexity' => 'medium',
            'savings_potential' => 'medium',
        ],
        'clickup' => [
            'category' => 'Project Management',
            'alternative' => 'Custom PM in Zao Dash',
            'complexity' => 'medium',
            'savings_potential' => 'medium',
        ],
        'docusign' => [
            'category' => 'E-Signatures',
            'alternative' => 'DocuSeal self-hosted',
            'complexity' => 'low',
            'savings_potential' => 'high',
        ],
        'dropbox' => [
            'category' => 'File Storage',
            'alternative' => 'Nextcloud self-hosted or S3 direct',
            'complexity' => 'low',
            'savings_potential' => 'medium',
        ],
        'box' => [
            'category' => 'File Storage',
            'alternative' => 'Nextcloud self-hosted or S3 direct',
            'complexity' => 'low',
            'savings_potential' => 'medium',
        ],
        'grammarly' => [
            'category' => 'Writing Assistant',
            'alternative' => 'LanguageTool self-hosted',
            'complexity' => 'medium',
            'savings_potential' => 'medium',
        ],
        'sentry' => [
            'category' => 'Error Tracking',
            'alternative' => 'Sentry self-hosted or GlitchTip',
            'complexity' => 'low',
            'savings_potential' => 'medium',
        ],
        'datadog' => [
            'category' => 'Monitoring',
            'alternative' => 'Grafana + Prometheus self-hosted',
            'complexity' => 'medium',
            'savings_potential' => 'high',
        ],
        'new relic' => [
            'category' => 'APM',
            'alternative' => 'Grafana + OpenTelemetry self-hosted',
            'complexity' => 'medium',
            'savings_potential' => 'high',
        ],
        'postmark' => [
            'category' => 'Transactional Email',
            'alternative' => 'Self-hosted SMTP or AWS SES direct',
            'complexity' => 'low',
            'savings_potential' => 'medium',
        ],
        'sendgrid' => [
            'category' => 'Email Delivery',
            'alternative' => 'AWS SES direct integration',
            'complexity' => 'low',
            'savings_potential' => 'medium',
        ],
        'twilio' => [
            'category' => 'SMS/Voice',
            'alternative' => 'Limited - consider usage optimization',
            'complexity' => 'very high',
            'savings_potential' => 'low',
        ],
        'stripe' => [
            'category' => 'Payments',
            'alternative' => 'Not recommended to replace',
            'complexity' => 'very high',
            'savings_potential' => 'none',
        ],
    ];

    public function __construct(QuickBooksApiService $qboService)
    {
        $this->qboService = $qboService;
    }

    public function name(): string
    {
        return 'Analyze Recurring Subscriptions';
    }

    public function description(): string
    {
        return 'Analyze QuickBooks expenses to identify recurring SaaS subscriptions and suggest which ones could be built in-house or self-hosted to reduce costs. Returns monthly spend by vendor, identifies subscription patterns, and provides build-vs-buy recommendations.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'months_back' => [
                    'type' => 'integer',
                    'description' => 'Number of months to analyze. Default: 6.',
                    'default' => 6,
                ],
                'min_monthly_amount' => [
                    'type' => 'number',
                    'description' => 'Minimum monthly amount to include in analysis. Default: 10.',
                    'default' => 10,
                ],
                'include_all_recurring' => [
                    'type' => 'boolean',
                    'description' => 'Include all recurring expenses, not just known SaaS. Default: false.',
                    'default' => false,
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        $connection = QuickBooksConnection::whereNotNull('access_token')
            ->where('is_active', true)
            ->first();

        if (! $connection) {
            return [
                'success' => false,
                'error' => 'No active QuickBooks connection found.',
            ];
        }

        $monthsBack = $params['months_back'] ?? 6;
        $minAmount = $params['min_monthly_amount'] ?? 10;
        $includeAll = $params['include_all_recurring'] ?? false;

        $fromDate = now()->subMonths($monthsBack)->startOfMonth()->format('Y-m-d');
        $toDate = now()->format('Y-m-d');

        try {
            // Get all purchases in the date range
            $expenses = $this->qboService->getExpenses($connection, $fromDate, $toDate, 1000);

            // Group by vendor and calculate patterns
            $vendorAnalysis = $this->analyzeVendorPatterns($expenses, $monthsBack);

            // Filter to recurring subscriptions
            $subscriptions = $this->identifySubscriptions($vendorAnalysis, $minAmount, $includeAll);

            // Generate recommendations
            $recommendations = $this->generateRecommendations($subscriptions);

            // Calculate totals
            $totalMonthly = array_sum(array_column($subscriptions, 'avg_monthly'));
            $totalAnnual = $totalMonthly * 12;
            $potentialSavings = $this->calculatePotentialSavings($recommendations);

            return [
                'success' => true,
                'analysis_period' => [
                    'from' => $fromDate,
                    'to' => $toDate,
                    'months' => $monthsBack,
                ],
                'summary' => [
                    'total_subscriptions_found' => count($subscriptions),
                    'total_monthly_spend' => round($totalMonthly, 2),
                    'total_annual_spend' => round($totalAnnual, 2),
                    'buildable_services_found' => count(array_filter($recommendations, fn ($r) => $r['has_alternative'])),
                    'potential_annual_savings' => round($potentialSavings, 2),
                ],
                'subscriptions' => $subscriptions,
                'recommendations' => $recommendations,
                'build_priorities' => $this->prioritizeBuilds($recommendations),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function analyzeVendorPatterns(array $expenses, int $monthsBack): array
    {
        $vendorData = [];

        foreach ($expenses as $expense) {
            $vendor = $expense['EntityRef']['name'] ?? 'Unknown';
            $vendorLower = strtolower($vendor);
            $amount = $expense['TotalAmt'] ?? 0;
            $date = Carbon::parse($expense['TxnDate']);
            $monthKey = $date->format('Y-m');

            if (! isset($vendorData[$vendorLower])) {
                $vendorData[$vendorLower] = [
                    'vendor_name' => $vendor,
                    'transactions' => [],
                    'months' => [],
                    'total' => 0,
                ];
            }

            $vendorData[$vendorLower]['transactions'][] = [
                'date' => $expense['TxnDate'],
                'amount' => $amount,
            ];
            $vendorData[$vendorLower]['months'][$monthKey] = ($vendorData[$vendorLower]['months'][$monthKey] ?? 0) + $amount;
            $vendorData[$vendorLower]['total'] += $amount;
        }

        // Calculate patterns
        foreach ($vendorData as $key => &$data) {
            $monthlyAmounts = array_values($data['months']);
            $data['months_with_charges'] = count($data['months']);
            $data['avg_monthly'] = $data['months_with_charges'] > 0
                ? $data['total'] / $data['months_with_charges']
                : 0;
            $data['is_recurring'] = $data['months_with_charges'] >= ($monthsBack * 0.5); // At least half the months
            $data['consistency_score'] = $this->calculateConsistency($monthlyAmounts);
        }

        return $vendorData;
    }

    protected function calculateConsistency(array $amounts): float
    {
        if (count($amounts) < 2) {
            return 0;
        }

        $avg = array_sum($amounts) / count($amounts);
        if ($avg == 0) {
            return 0;
        }

        $variance = array_sum(array_map(fn ($x) => pow($x - $avg, 2), $amounts)) / count($amounts);
        $stdDev = sqrt($variance);
        $cv = $stdDev / $avg; // Coefficient of variation

        // Lower CV = more consistent. Convert to 0-1 score where 1 is most consistent
        return max(0, 1 - min($cv, 1));
    }

    protected function identifySubscriptions(array $vendorAnalysis, float $minAmount, bool $includeAll): array
    {
        $subscriptions = [];

        foreach ($vendorAnalysis as $key => $data) {
            if (! $data['is_recurring'] || $data['avg_monthly'] < $minAmount) {
                continue;
            }

            // Check if it's a known SaaS or include all recurring
            $isKnownSaas = false;
            $matchedService = null;

            foreach ($this->buildableServices as $service => $info) {
                if (str_contains($key, $service)) {
                    $isKnownSaas = true;
                    $matchedService = $service;
                    break;
                }
            }

            if ($isKnownSaas || $includeAll) {
                $subscriptions[] = [
                    'vendor' => $data['vendor_name'],
                    'vendor_key' => $key,
                    'matched_service' => $matchedService,
                    'avg_monthly' => round($data['avg_monthly'], 2),
                    'total_spent' => round($data['total'], 2),
                    'months_charged' => $data['months_with_charges'],
                    'consistency_score' => round($data['consistency_score'], 2),
                    'is_known_saas' => $isKnownSaas,
                ];
            }
        }

        // Sort by monthly spend descending
        usort($subscriptions, fn ($a, $b) => $b['avg_monthly'] <=> $a['avg_monthly']);

        return $subscriptions;
    }

    protected function generateRecommendations(array $subscriptions): array
    {
        $recommendations = [];

        foreach ($subscriptions as $sub) {
            $serviceKey = $sub['matched_service'];
            $hasAlternative = $serviceKey && isset($this->buildableServices[$serviceKey]);

            $rec = [
                'vendor' => $sub['vendor'],
                'monthly_cost' => $sub['avg_monthly'],
                'annual_cost' => round($sub['avg_monthly'] * 12, 2),
                'has_alternative' => $hasAlternative,
            ];

            if ($hasAlternative) {
                $info = $this->buildableServices[$serviceKey];
                $rec['category'] = $info['category'];
                $rec['alternative'] = $info['alternative'];
                $rec['build_complexity'] = $info['complexity'];
                $rec['savings_potential'] = $info['savings_potential'];
                $rec['recommendation'] = $this->getRecommendationText($sub, $info);
            } else {
                $rec['category'] = 'Unknown/Other';
                $rec['alternative'] = null;
                $rec['build_complexity'] = null;
                $rec['savings_potential'] = null;
                $rec['recommendation'] = 'Review usage and consider if this service is necessary.';
            }

            $recommendations[] = $rec;
        }

        return $recommendations;
    }

    protected function getRecommendationText(array $subscription, array $serviceInfo): string
    {
        $annual = round($subscription['avg_monthly'] * 12, 2);
        $complexity = $serviceInfo['complexity'];
        $savings = $serviceInfo['savings_potential'];

        if ($savings === 'none' || $complexity === 'very high') {
            return "Keep current service. Building alternative not recommended due to {$complexity} complexity.";
        }

        if ($savings === 'high' && in_array($complexity, ['low', 'medium'])) {
            return "STRONG CANDIDATE: {$serviceInfo['alternative']}. Annual spend: \${$annual}. {$complexity} complexity build.";
        }

        if ($savings === 'medium') {
            return "Consider: {$serviceInfo['alternative']}. Moderate savings potential at \${$annual}/year.";
        }

        return "Optional: {$serviceInfo['alternative']}. Review if current usage justifies cost.";
    }

    protected function calculatePotentialSavings(array $recommendations): float
    {
        $savings = 0;

        foreach ($recommendations as $rec) {
            if (! $rec['has_alternative']) {
                continue;
            }

            $savingsMultiplier = match ($rec['savings_potential']) {
                'very high' => 0.9,
                'high' => 0.75,
                'medium' => 0.5,
                'low' => 0.25,
                default => 0,
            };

            // Only count if complexity is reasonable
            if (! in_array($rec['build_complexity'], ['very high'])) {
                $savings += $rec['annual_cost'] * $savingsMultiplier;
            }
        }

        return $savings;
    }

    protected function prioritizeBuilds(array $recommendations): array
    {
        $priorities = [];

        foreach ($recommendations as $rec) {
            if (! $rec['has_alternative'] || $rec['savings_potential'] === 'none') {
                continue;
            }

            // Score based on savings potential and inverse of complexity
            $savingsScore = match ($rec['savings_potential']) {
                'very high' => 4,
                'high' => 3,
                'medium' => 2,
                'low' => 1,
                default => 0,
            };

            $complexityScore = match ($rec['build_complexity']) {
                'low' => 4,
                'medium' => 3,
                'high' => 2,
                'very high' => 1,
                default => 0,
            };

            $costScore = min($rec['annual_cost'] / 500, 4); // Normalize cost impact

            $totalScore = ($savingsScore * 2) + ($complexityScore * 1.5) + $costScore;

            if ($totalScore >= 5) { // Only include worthwhile builds
                $priorities[] = [
                    'vendor' => $rec['vendor'],
                    'category' => $rec['category'],
                    'annual_cost' => $rec['annual_cost'],
                    'alternative' => $rec['alternative'],
                    'complexity' => $rec['build_complexity'],
                    'priority_score' => round($totalScore, 1),
                    'action' => $this->getPriorityAction($rec),
                ];
            }
        }

        // Sort by priority score descending
        usort($priorities, fn ($a, $b) => $b['priority_score'] <=> $a['priority_score']);

        return array_slice($priorities, 0, 5); // Top 5 priorities
    }

    protected function getPriorityAction(array $rec): string
    {
        if ($rec['build_complexity'] === 'low' && $rec['savings_potential'] === 'high') {
            return 'BUILD NOW - Quick win with high ROI';
        }
        if ($rec['build_complexity'] === 'medium' && $rec['savings_potential'] === 'high') {
            return 'PLAN BUILD - Good ROI, schedule development sprint';
        }
        if ($rec['build_complexity'] === 'low') {
            return 'CONSIDER - Low effort, moderate benefit';
        }

        return 'EVALUATE - Assess actual usage before deciding';
    }
}
