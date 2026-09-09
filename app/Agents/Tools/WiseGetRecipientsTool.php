<?php

namespace App\Agents\Tools;

use App\Models\WiseConnection;
use App\Services\Wise\WiseApiService;

class WiseGetRecipientsTool extends BaseTool
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
        return 'Get Wise Recipients';
    }

    public function description(): string
    {
        return 'List all configured payment recipients in Wise. Use this to find recipient IDs for initiating transfers.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'currency' => [
                    'type' => 'string',
                    'description' => 'Optional: filter recipients by currency (e.g., USD, PKR, EUR).',
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
                'error' => 'No active Wise connection found.',
                'recipients' => [],
            ];
        }

        try {
            $recipients = $this->wiseService->listRecipients($connection, $params['currency'] ?? null);

            $formatted = array_map(fn ($r) => [
                'id' => $r['id'],
                'name' => $r['accountHolderName'],
                'currency' => $r['currency'],
                'type' => $r['type'],
                'active' => $r['active'],
                'country' => $r['country'] ?? null,
            ], $recipients);

            // Filter to active only
            $formatted = array_filter($formatted, fn ($r) => $r['active']);

            return [
                'success' => true,
                'count' => count($formatted),
                'recipients' => array_values($formatted),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'recipients' => [],
            ];
        }
    }
}
