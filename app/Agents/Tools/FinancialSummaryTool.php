<?php

namespace App\Agents\Tools;

use App\Models\QuickBooksConnection;
use App\Models\WiseConnection;
use App\Services\QuickBooks\QuickBooksApiService;
use App\Services\Wise\WiseApiService;

class FinancialSummaryTool extends BaseTool
{
    protected QuickBooksApiService $qboService;

    protected WiseApiService $wiseService;

    public function __construct(QuickBooksApiService $qboService, WiseApiService $wiseService)
    {
        $this->qboService = $qboService;
        $this->wiseService = $wiseService;
    }

    public function category(): string
    {
        return 'financial';
    }

    public function name(): string
    {
        return 'Get Financial Summary';
    }

    public function description(): string
    {
        return 'Get a comprehensive financial snapshot including cash position across all accounts, accounts receivable, accounts payable, and key metrics.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'include_wise' => [
                    'type' => 'boolean',
                    'description' => 'Include Wise multi-currency balances. Default: true.',
                    'default' => true,
                ],
                'include_ar_details' => [
                    'type' => 'boolean',
                    'description' => 'Include detailed accounts receivable breakdown. Default: false.',
                    'default' => false,
                ],
                'include_ap_details' => [
                    'type' => 'boolean',
                    'description' => 'Include detailed accounts payable breakdown. Default: false.',
                    'default' => false,
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        $qboConnection = QuickBooksConnection::active()->first();
        $wiseConnection = WiseConnection::active()->first();

        $includeWise = $params['include_wise'] ?? true;
        $includeArDetails = $params['include_ar_details'] ?? false;
        $includeApDetails = $params['include_ap_details'] ?? false;

        $result = [
            'success' => true,
            'as_of' => now()->toIso8601String(),
            'cash_position' => [],
            'accounts_receivable' => [],
            'accounts_payable' => [],
            'metrics' => [],
        ];

        // QuickBooks data
        if ($qboConnection) {
            try {
                $result['cash_position']['qbo'] = $this->getQboCashPosition($qboConnection);
                $result['accounts_receivable'] = $this->getAccountsReceivable($qboConnection, $includeArDetails);
                $result['accounts_payable'] = $this->getAccountsPayable($qboConnection, $includeApDetails);
            } catch (\Exception $e) {
                $result['qbo_error'] = $e->getMessage();
            }
        } else {
            $result['qbo_error'] = 'No active QuickBooks connection';
        }

        // Wise balances
        if ($includeWise && $wiseConnection) {
            try {
                $balances = $this->wiseService->getBalances($wiseConnection);
                $result['cash_position']['wise'] = array_map(fn ($b) => [
                    'currency' => $b['currency'],
                    'amount' => $b['amount']['value'],
                    'available' => ($b['amount']['value'] ?? 0) - ($b['reservedAmount']['value'] ?? 0),
                ], $balances);
            } catch (\Exception $e) {
                $result['wise_error'] = $e->getMessage();
            }
        }

        // Calculate totals and metrics
        $result['totals'] = $this->calculateTotals($result);
        $result['metrics'] = $this->calculateMetrics($result);

        return $result;
    }

    protected function getQboCashPosition(QuickBooksConnection $connection): array
    {
        $accounts = $this->qboService->getAccounts($connection, 'Bank');

        return array_map(fn ($a) => [
            'name' => $a['Name'],
            'type' => $a['AccountSubType'] ?? $a['AccountType'],
            'balance' => $a['CurrentBalance'] ?? 0,
            'currency' => $a['CurrencyRef']['value'] ?? 'USD',
        ], $accounts);
    }

    protected function getAccountsReceivable(QuickBooksConnection $connection, bool $detailed): array
    {
        try {
            // Get AR aging summary
            $report = $this->qboService->getArAging($connection);

            $summary = [
                'total' => 0,
                'current' => 0,
                'overdue_1_30' => 0,
                'overdue_31_60' => 0,
                'overdue_61_90' => 0,
                'overdue_90_plus' => 0,
            ];

            // Parse the aging report
            if (isset($report['Rows']['Row'])) {
                foreach ($report['Rows']['Row'] as $row) {
                    if (isset($row['Summary']['ColData'])) {
                        $cols = $row['Summary']['ColData'];
                        $summary['current'] += (float) ($cols[1]['value'] ?? 0);
                        $summary['overdue_1_30'] += (float) ($cols[2]['value'] ?? 0);
                        $summary['overdue_31_60'] += (float) ($cols[3]['value'] ?? 0);
                        $summary['overdue_61_90'] += (float) ($cols[4]['value'] ?? 0);
                        $summary['overdue_90_plus'] += (float) ($cols[5]['value'] ?? 0);
                    }
                }
            }

            $summary['total'] = $summary['current'] + $summary['overdue_1_30'] +
                               $summary['overdue_31_60'] + $summary['overdue_61_90'] +
                               $summary['overdue_90_plus'];

            $result = ['summary' => $summary];

            if ($detailed) {
                $invoices = $this->qboService->getOpenInvoices($connection);
                $result['invoices'] = array_map(fn ($i) => [
                    'number' => $i['DocNumber'] ?? 'N/A',
                    'customer' => $i['CustomerRef']['name'] ?? 'Unknown',
                    'amount' => $i['TotalAmt'] ?? 0,
                    'balance' => $i['Balance'] ?? 0,
                    'due_date' => $i['DueDate'] ?? null,
                    'days_overdue' => isset($i['DueDate'])
                        ? max(0, now()->diffInDays($i['DueDate'], false) * -1)
                        : 0,
                ], array_slice($invoices, 0, 20));
            }

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    protected function getAccountsPayable(QuickBooksConnection $connection, bool $detailed): array
    {
        try {
            // Get AP aging summary
            $report = $this->qboService->getApAging($connection);

            $summary = [
                'total' => 0,
                'current' => 0,
                'overdue_1_30' => 0,
                'overdue_31_60' => 0,
                'overdue_61_90' => 0,
                'overdue_90_plus' => 0,
            ];

            // Parse the aging report
            if (isset($report['Rows']['Row'])) {
                foreach ($report['Rows']['Row'] as $row) {
                    if (isset($row['Summary']['ColData'])) {
                        $cols = $row['Summary']['ColData'];
                        $summary['current'] += (float) ($cols[1]['value'] ?? 0);
                        $summary['overdue_1_30'] += (float) ($cols[2]['value'] ?? 0);
                        $summary['overdue_31_60'] += (float) ($cols[3]['value'] ?? 0);
                        $summary['overdue_61_90'] += (float) ($cols[4]['value'] ?? 0);
                        $summary['overdue_90_plus'] += (float) ($cols[5]['value'] ?? 0);
                    }
                }
            }

            $summary['total'] = $summary['current'] + $summary['overdue_1_30'] +
                               $summary['overdue_31_60'] + $summary['overdue_61_90'] +
                               $summary['overdue_90_plus'];

            $result = ['summary' => $summary];

            if ($detailed) {
                $bills = $this->qboService->getOpenBills($connection);
                $result['bills'] = array_map(fn ($b) => [
                    'vendor' => $b['VendorRef']['name'] ?? 'Unknown',
                    'amount' => $b['TotalAmt'] ?? 0,
                    'balance' => $b['Balance'] ?? 0,
                    'due_date' => $b['DueDate'] ?? null,
                ], array_slice($bills, 0, 20));
            }

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    protected function calculateTotals(array $data): array
    {
        $totalCash = 0;

        // Sum QBO bank accounts
        foreach ($data['cash_position']['qbo'] ?? [] as $account) {
            // Simplified: assumes USD
            $totalCash += $account['balance'] ?? 0;
        }

        // Sum Wise balances (simplified: USD only or convert)
        foreach ($data['cash_position']['wise'] ?? [] as $balance) {
            if ($balance['currency'] === 'USD') {
                $totalCash += $balance['available'] ?? 0;
            }
        }

        return [
            'total_cash_usd' => round($totalCash, 2),
            'total_ar' => $data['accounts_receivable']['summary']['total'] ?? 0,
            'total_ap' => $data['accounts_payable']['summary']['total'] ?? 0,
            'net_position' => round(
                $totalCash +
                ($data['accounts_receivable']['summary']['total'] ?? 0) -
                ($data['accounts_payable']['summary']['total'] ?? 0),
                2
            ),
        ];
    }

    protected function calculateMetrics(array $data): array
    {
        $ar = $data['accounts_receivable']['summary'] ?? [];
        $ap = $data['accounts_payable']['summary'] ?? [];
        $totalCash = $data['totals']['total_cash_usd'] ?? 0;
        $totalAr = $ar['total'] ?? 0;
        $totalAp = $ap['total'] ?? 0;

        return [
            'current_ratio' => $totalAp > 0
                ? round(($totalCash + $totalAr) / $totalAp, 2)
                : null,
            'ar_overdue_percent' => $totalAr > 0
                ? round((($ar['overdue_1_30'] ?? 0) + ($ar['overdue_31_60'] ?? 0) +
                        ($ar['overdue_61_90'] ?? 0) + ($ar['overdue_90_plus'] ?? 0)) / $totalAr * 100, 1)
                : 0,
            'ap_overdue_percent' => $totalAp > 0
                ? round((($ap['overdue_1_30'] ?? 0) + ($ap['overdue_31_60'] ?? 0) +
                        ($ap['overdue_61_90'] ?? 0) + ($ap['overdue_90_plus'] ?? 0)) / $totalAp * 100, 1)
                : 0,
            'days_cash_on_hand' => $totalAp > 0
                ? round($totalCash / ($totalAp / 30), 0)
                : null,
        ];
    }
}
