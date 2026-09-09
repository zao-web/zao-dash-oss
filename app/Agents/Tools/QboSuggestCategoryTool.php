<?php

namespace App\Agents\Tools;

use App\Models\QuickBooksConnection;
use App\Services\QuickBooks\QuickBooksApiService;

/**
 * Get AI-suggested category for an expense based on vendor and description.
 */
class QboSuggestCategoryTool extends BaseTool
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
        return 'Suggest Expense Category';
    }

    public function description(): string
    {
        return 'Get a suggested expense category based on vendor name and description. Uses pattern matching to recommend the most tax-advantaged category. Always verify suggestions before applying.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'vendor_name' => [
                    'type' => 'string',
                    'description' => 'The vendor/payee name from the expense.',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Any description or memo on the expense.',
                ],
                'amount' => [
                    'type' => 'number',
                    'description' => 'The expense amount (may help with categorization).',
                ],
            ],
            'required' => ['vendor_name'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'vendor_name' => 'required|string|min:1',
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
            $suggestion = $this->qboService->suggestCategory(
                $connection,
                $params['vendor_name'],
                $params['description'] ?? null,
                $params['amount'] ?? null
            );

            return [
                'success' => true,
                'vendor' => $params['vendor_name'],
                'suggestion' => $suggestion,
                'warning' => $suggestion['confidence'] === 'low'
                    ? 'Low confidence suggestion - manual review recommended.'
                    : null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
