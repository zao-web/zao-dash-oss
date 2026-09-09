<?php

namespace App\Agents\Tools;

use App\Models\QuickBooksConnection;
use App\Services\QuickBooks\QuickBooksApiService;

/**
 * Get available expense categories from QuickBooks.
 */
class QboGetCategoriesTool extends BaseTool
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
        return 'Get QuickBooks Categories';
    }

    public function description(): string
    {
        return 'Get available expense account categories from QuickBooks, including tax-deductible categories and IRS tax category mappings. Use this to understand what categories are available before categorizing expenses.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'filter' => [
                    'type' => 'string',
                    'enum' => ['all', 'tax_deductible'],
                    'description' => 'Filter: "all" for all expense accounts, "tax_deductible" for tax-advantaged categories.',
                    'default' => 'all',
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
                'categories' => [],
            ];
        }

        $filter = $params['filter'] ?? 'all';

        try {
            $categories = $this->qboService->getTaxDeductibleCategories($connection);

            if ($filter === 'tax_deductible') {
                $categories = array_filter($categories, fn ($c) => $c['tax_deductible']);
            }

            // Group by tax category for easier use
            $grouped = [];
            foreach ($categories as $cat) {
                $taxCat = $cat['tax_category'];
                if (! isset($grouped[$taxCat])) {
                    $grouped[$taxCat] = [];
                }
                $grouped[$taxCat][] = [
                    'id' => $cat['id'],
                    'name' => $cat['name'],
                    'sub_type' => $cat['sub_type'],
                    'tax_deductible' => $cat['tax_deductible'],
                ];
            }

            return [
                'success' => true,
                'filter' => $filter,
                'total_count' => count($categories),
                'categories_by_tax_type' => $grouped,
                'flat_list' => array_values($categories),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'categories' => [],
            ];
        }
    }
}
