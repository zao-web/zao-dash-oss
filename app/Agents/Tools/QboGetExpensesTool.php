<?php

namespace App\Agents\Tools;

use App\Models\QuickBooksConnection;
use App\Services\QuickBooks\QuickBooksApiService;

/**
 * Get expenses from QuickBooks for review and categorization.
 */
class QboGetExpensesTool extends BaseTool
{
    protected QuickBooksApiService $qboService;

    public function __construct(QuickBooksApiService $qboService)
    {
        $this->qboService = $qboService;
    }

    public function category(): string
    {
        return 'quickbooks';
    }

    public function name(): string
    {
        return 'Get QuickBooks Expenses';
    }

    public function description(): string
    {
        return 'Retrieve expenses from QuickBooks. Can fetch all expenses, uncategorized expenses only, or expenses within a date range. Use this to review and identify expenses that need categorization.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => [
                    'type' => 'string',
                    'enum' => ['all', 'uncategorized'],
                    'description' => 'Filter type: "all" for all expenses, "uncategorized" for expenses needing categorization.',
                    'default' => 'uncategorized',
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Start date (YYYY-MM-DD format). Defaults to 30 days ago.',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'End date (YYYY-MM-DD format). Defaults to today.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Maximum number of expenses to return. Default: 50.',
                    'default' => 50,
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
                'expenses' => [],
            ];
        }

        $filter = $params['filter'] ?? 'uncategorized';
        $fromDate = $params['from_date'] ?? now()->subDays(30)->format('Y-m-d');
        $toDate = $params['to_date'] ?? now()->format('Y-m-d');
        $limit = min($params['limit'] ?? 50, 100);

        try {
            if ($filter === 'uncategorized') {
                $expenses = $this->qboService->getUncategorizedExpenses($connection, $fromDate, $toDate);
            } else {
                $expenses = $this->qboService->getExpenses($connection, $fromDate, $toDate, $limit);
            }

            // Transform to a more readable format
            $formatted = array_map(function ($expense) {
                $vendor = $expense['EntityRef']['name'] ?? 'Unknown Vendor';
                $lines = [];

                foreach ($expense['Line'] ?? [] as $index => $line) {
                    if ($line['DetailType'] === 'AccountBasedExpenseLineDetail') {
                        $lines[] = [
                            'line_num' => $index,
                            'amount' => $line['Amount'] ?? 0,
                            'description' => $line['Description'] ?? '',
                            'account_id' => $line['AccountBasedExpenseLineDetail']['AccountRef']['value'] ?? '',
                            'account_name' => $line['AccountBasedExpenseLineDetail']['AccountRef']['name'] ?? 'Uncategorized',
                        ];
                    }
                }

                return [
                    'id' => $expense['Id'],
                    'date' => $expense['TxnDate'],
                    'vendor' => $vendor,
                    'total_amount' => $expense['TotalAmt'] ?? 0,
                    'payment_type' => $expense['PaymentType'] ?? 'Unknown',
                    'memo' => $expense['PrivateNote'] ?? '',
                    'lines' => $lines,
                ];
            }, array_slice($expenses, 0, $limit));

            return [
                'success' => true,
                'filter' => $filter,
                'date_range' => ['from' => $fromDate, 'to' => $toDate],
                'count' => count($formatted),
                'expenses' => $formatted,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'expenses' => [],
            ];
        }
    }
}
