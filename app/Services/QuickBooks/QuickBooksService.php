<?php

namespace App\Services\QuickBooks;

use App\Models\QuickBooksConnection;
use Carbon\Carbon;

/**
 * QuickBooks service for listing data from QBO API.
 *
 * This service provides list methods that return raw QBO data without
 * persisting to the database. Used by SyncQuickBooksJob for data sync.
 */
class QuickBooksService
{
    public function __construct(
        protected QuickBooksApiService $api
    ) {}

    /**
     * List all active customers from QuickBooks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCustomers(QuickBooksConnection $connection): array
    {
        $result = $this->api->query(
            $connection,
            'SELECT * FROM Customer MAXRESULTS 1000'
        );

        return $result['Customer'] ?? [];
    }

    /**
     * List invoices from QuickBooks, optionally filtered by date.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInvoices(QuickBooksConnection $connection, ?Carbon $fromDate = null): array
    {
        $sql = 'SELECT * FROM Invoice';

        if ($fromDate) {
            $sql .= " WHERE TxnDate >= '{$fromDate->toDateString()}'";
        }

        $sql .= ' MAXRESULTS 1000';

        $result = $this->api->query($connection, $sql);

        return $result['Invoice'] ?? [];
    }

    /**
     * List all active accounts from QuickBooks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(QuickBooksConnection $connection): array
    {
        $result = $this->api->query(
            $connection,
            'SELECT * FROM Account WHERE Active = true MAXRESULTS 1000'
        );

        return $result['Account'] ?? [];
    }

    /**
     * List transactions (Purchases, Payments, Deposits) from QuickBooks.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listTransactions(QuickBooksConnection $connection, ?Carbon $fromDate = null): array
    {
        $transactions = [];
        $types = ['Purchase', 'Payment', 'Deposit'];

        foreach ($types as $type) {
            $sql = "SELECT * FROM {$type}";

            if ($fromDate) {
                $sql .= " WHERE TxnDate >= '{$fromDate->toDateString()}'";
            }

            $sql .= ' MAXRESULTS 500';

            try {
                $result = $this->api->query($connection, $sql);

                foreach ($result[$type] ?? [] as $txn) {
                    $txn['TxnType'] = $type;
                    $transactions[] = $txn;
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to fetch {$type} transactions", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $transactions;
    }

    /**
     * Get financial reports summary from QuickBooks.
     *
     * @return array<string, mixed>
     */
    public function getFinancialReports(QuickBooksConnection $connection): array
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth()->toDateString();
        $endOfMonth = $now->copy()->endOfMonth()->toDateString();
        $today = $now->toDateString();

        $summary = [
            'total_revenue' => 0,
            'total_expenses' => 0,
            'net_income' => 0,
            'gross_profit' => 0,
            'accounts_receivable' => 0,
            'accounts_payable' => 0,
            'cash_on_hand' => 0,
            'total_assets' => 0,
            'total_liabilities' => 0,
            'total_equity' => 0,
        ];

        try {
            $pnl = $this->api->getProfitAndLoss($connection, $startOfMonth, $endOfMonth);
            $summary = array_merge($summary, $this->extractPnLData($pnl));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to get P&L report', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $balanceSheet = $this->api->getBalanceSheet($connection, $today);
            $summary = array_merge($summary, $this->extractBalanceSheetData($balanceSheet));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Failed to get balance sheet', [
                'error' => $e->getMessage(),
            ]);
        }

        return $summary;
    }

    /**
     * Extract financial data from P&L report.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, float>
     */
    protected function extractPnLData(array $report): array
    {
        $data = [
            'total_revenue' => 0,
            'total_expenses' => 0,
            'net_income' => 0,
            'gross_profit' => 0,
        ];

        $rows = $report['Rows']['Row'] ?? [];

        foreach ($rows as $row) {
            $summary = $row['Summary'] ?? [];
            $group = $row['group'] ?? '';

            if (isset($summary['ColData'])) {
                $value = (float) ($summary['ColData'][1]['value'] ?? 0);

                if ($group === 'Income' || str_contains(strtolower($row['Header']['ColData'][0]['value'] ?? ''), 'income')) {
                    $data['total_revenue'] = $value;
                } elseif ($group === 'Expenses' || str_contains(strtolower($row['Header']['ColData'][0]['value'] ?? ''), 'expense')) {
                    $data['total_expenses'] = abs($value);
                } elseif ($group === 'GrossProfit') {
                    $data['gross_profit'] = $value;
                } elseif ($group === 'NetIncome' || str_contains(strtolower($row['Header']['ColData'][0]['value'] ?? ''), 'net income')) {
                    $data['net_income'] = $value;
                }
            }
        }

        if ($data['net_income'] === 0 && ($data['total_revenue'] > 0 || $data['total_expenses'] > 0)) {
            $data['net_income'] = $data['total_revenue'] - $data['total_expenses'];
        }

        return $data;
    }

    /**
     * Extract financial data from Balance Sheet report.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, float>
     */
    protected function extractBalanceSheetData(array $report): array
    {
        $data = [
            'accounts_receivable' => 0,
            'accounts_payable' => 0,
            'cash_on_hand' => 0,
            'total_assets' => 0,
            'total_liabilities' => 0,
            'total_equity' => 0,
        ];

        $rows = $report['Rows']['Row'] ?? [];

        foreach ($rows as $row) {
            $this->processBalanceSheetRow($row, $data);
        }

        return $data;
    }

    /**
     * Process a balance sheet row recursively.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, float>  $data
     */
    protected function processBalanceSheetRow(array $row, array &$data): void
    {
        $group = $row['group'] ?? '';
        $header = strtolower($row['Header']['ColData'][0]['value'] ?? '');

        if (isset($row['Summary']['ColData'])) {
            $value = (float) ($row['Summary']['ColData'][1]['value'] ?? 0);

            if (str_contains($header, 'accounts receivable') || $group === 'AccountsReceivable') {
                $data['accounts_receivable'] = $value;
            } elseif (str_contains($header, 'accounts payable') || $group === 'AccountsPayable') {
                $data['accounts_payable'] = abs($value);
            } elseif (str_contains($header, 'bank') || str_contains($header, 'cash') || $group === 'BankAccounts') {
                $data['cash_on_hand'] += $value;
            } elseif ($group === 'TotalAssets' || str_contains($header, 'total assets')) {
                $data['total_assets'] = $value;
            } elseif ($group === 'TotalLiabilities' || str_contains($header, 'total liabilities')) {
                $data['total_liabilities'] = abs($value);
            } elseif ($group === 'TotalEquity' || str_contains($header, 'total equity')) {
                $data['total_equity'] = $value;
            }
        }

        foreach ($row['Rows']['Row'] ?? [] as $nestedRow) {
            $this->processBalanceSheetRow($nestedRow, $data);
        }
    }
}
