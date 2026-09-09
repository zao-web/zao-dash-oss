<?php

namespace App\Agents\Tools;

use App\Models\WiseConnection;
use App\Services\Wise\WiseApiService;

class WiseGetBalancesTool extends BaseTool
{
    protected WiseApiService $wiseService;

    public function __construct(WiseApiService $wiseService)
    {
        $this->wiseService = $wiseService;
    }

    public function category(): string
    {
        return 'wise';
    }

    public function name(): string
    {
        return 'Get Wise Balances';
    }

    public function description(): string
    {
        return 'Get current multi-currency balances from the connected Wise account. Use this to check available funds before initiating payments.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'currency' => [
                    'type' => 'string',
                    'description' => 'Optional: filter to a specific currency (e.g., USD, EUR, GBP). If not provided, returns all balances.',
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        $connection = WiseConnection::active()->first();

        if (! $connection) {
            return [
                'success' => false,
                'error' => 'No active Wise connection found. Please connect Wise in Settings.',
                'balances' => [],
            ];
        }

        try {
            if (isset($params['currency'])) {
                $balance = $this->wiseService->getBalance($connection, $params['currency']);

                if (! $balance) {
                    return [
                        'success' => true,
                        'message' => "No {$params['currency']} balance found.",
                        'balances' => [],
                    ];
                }

                return [
                    'success' => true,
                    'balances' => [[
                        'currency' => $balance['currency'],
                        'amount' => $balance['amount']['value'],
                        'reserved' => $balance['reservedAmount']['value'] ?? 0,
                        'available' => ($balance['amount']['value'] ?? 0) - ($balance['reservedAmount']['value'] ?? 0),
                    ]],
                ];
            }

            $balances = $this->wiseService->getBalances($connection);

            return [
                'success' => true,
                'balances' => array_map(fn ($b) => [
                    'currency' => $b['currency'],
                    'amount' => $b['amount']['value'],
                    'reserved' => $b['reservedAmount']['value'] ?? 0,
                    'available' => ($b['amount']['value'] ?? 0) - ($b['reservedAmount']['value'] ?? 0),
                ], $balances),
                'synced_at' => now()->toIso8601String(),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'balances' => [],
            ];
        }
    }
}
