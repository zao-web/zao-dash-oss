<?php

namespace App\Agents\Tools;

use App\Services\CapabilitySynthesisService;

/**
 * Get focus recommendations - what the user should work on right now.
 *
 * This tool surfaces human-required items: approvals, at-risk clients,
 * stale leads, overdue invoices, and other decisions only humans can make.
 */
class GetFocusTool extends BaseTool
{
    public function __construct(
        protected CapabilitySynthesisService $synthesisService
    ) {}

    public function category(): string
    {
        return 'navigation';
    }

    public function name(): string
    {
        return 'Get Focus';
    }

    public function description(): string
    {
        return 'Get recommendations for what the user should focus on right now. Returns human-required items like pending approvals, at-risk clients, stale leads, and overdue invoices. Use this when the user asks "What should I focus on?", "What needs my attention?", or similar questions.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'type' => [
                    'type' => 'string',
                    'enum' => ['briefing', 'items', 'capabilities', 'gaps'],
                    'description' => 'Type of focus info: "briefing" for morning summary, "items" for full list, "capabilities" for what\'s automated, "gaps" for automation opportunities',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max items to return (default: 10)',
                ],
                'priority_filter' => [
                    'type' => 'string',
                    'enum' => ['all', 'critical', 'high'],
                    'description' => 'Filter by priority level',
                ],
            ],
        ];
    }

    public function execute(array $params): array
    {
        $type = $params['type'] ?? 'briefing';
        $limit = $params['limit'] ?? 10;
        $priorityFilter = $params['priority_filter'] ?? 'all';

        switch ($type) {
            case 'briefing':
                $briefing = $this->synthesisService->getMorningBriefing();

                return [
                    'greeting' => $briefing['greeting'],
                    'summary' => $briefing['summary'],
                    'recommendations' => $briefing['recommendations'],
                    'top_priorities' => array_slice($briefing['top_priorities'], 0, 5),
                ];

            case 'items':
                $items = $this->synthesisService->getHumanRequiredItems();

                // Apply priority filter
                if ($priorityFilter !== 'all') {
                    $items = array_filter($items, fn ($item) => $priorityFilter === 'critical'
                            ? $item['priority'] === 'critical'
                            : in_array($item['priority'], ['critical', 'high'])
                    );
                    $items = array_values($items);
                }

                return [
                    'total' => count($items),
                    'items' => array_slice($items, 0, $limit),
                ];

            case 'capabilities':
                return $this->synthesisService->getCapabilitySummary();

            case 'gaps':
                return [
                    'gaps' => $this->synthesisService->getAutomationGaps(),
                ];

            default:
                return ['error' => 'Unknown focus type'];
        }
    }
}
