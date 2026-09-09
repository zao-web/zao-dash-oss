<?php

namespace App\Agents\Tools;

use App\Models\QuickBooksConnection;
use App\Services\QuickBooks\QuickBooksApiService;

/**
 * Categorize an expense in QuickBooks.
 */
class QboCategorizeExpenseTool extends BaseTool
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
        return 'Categorize QuickBooks Expense';
    }

    public function description(): string
    {
        return 'Update the category of an expense in QuickBooks. Use this after identifying uncategorized expenses and determining the correct category for tax purposes.';
    }

    public function requiresApproval(): bool
    {
        return true; // Financial changes need human approval
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'expense_id' => [
                    'type' => 'string',
                    'description' => 'The QuickBooks Purchase ID to update.',
                ],
                'account_id' => [
                    'type' => 'string',
                    'description' => 'The expense account ID to categorize the expense under.',
                ],
                'line_num' => [
                    'type' => 'integer',
                    'description' => 'Specific line number to update. If not provided, updates all lines.',
                ],
                'reason' => [
                    'type' => 'string',
                    'description' => 'Explanation for the categorization choice (will be added as a memo).',
                ],
            ],
            'required' => ['expense_id', 'account_id', 'reason'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'expense_id' => 'required|string',
            'account_id' => 'required|string',
            'reason' => 'required|string|min:10',
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

        try {
            $result = $this->qboService->updateExpenseCategory(
                $connection,
                $params['expense_id'],
                $params['account_id'],
                $params['line_num'] ?? null,
                $params['reason']
            );

            return [
                'success' => true,
                'expense_id' => $params['expense_id'],
                'new_account_id' => $params['account_id'],
                'updated_expense' => [
                    'id' => $result['Id'],
                    'date' => $result['TxnDate'],
                    'total' => $result['TotalAmt'] ?? 0,
                    'vendor' => $result['EntityRef']['name'] ?? 'Unknown',
                ],
                'message' => 'Expense successfully categorized.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
