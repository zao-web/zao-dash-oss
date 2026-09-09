<?php

namespace App\Mcp\Tools;

use App\Services\CapabilitySynthesisService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetFocusTool extends Tool
{
    protected string $name = 'get-focus';

    protected string $title = 'Get Focus Briefing';

    protected string $description = 'Get recommendations for what needs human attention right now, including approvals, client risk, leads, invoices, and blocked work.';

    public function __construct(
        protected CapabilitySynthesisService $synthesisService
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $type = $request->get('type', 'briefing');
        $limit = min((int) $request->get('limit', 10), 25);
        $priorityFilter = $request->get('priority_filter', 'all');

        return match ($type) {
            'briefing' => Response::structured([
                'briefing' => $this->briefingPayload($priorityFilter),
            ]),
            'items' => Response::structured([
                'items' => $this->filteredItems($priorityFilter, $limit),
                'count' => count($this->filteredItems($priorityFilter, 250)),
                'priority_filter' => $priorityFilter,
            ]),
            'capabilities' => Response::structured([
                'capabilities' => $this->synthesisService->getCapabilitySummary(),
            ]),
            'gaps' => Response::structured([
                'gaps' => $this->synthesisService->getAutomationGaps(),
            ]),
            default => Response::structured([
                'error' => 'Unknown focus type.',
            ]),
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'type' => $schema->string()
                ->enum(['briefing', 'items', 'capabilities', 'gaps'])
                ->description('Focus response type. Defaults to briefing.'),
            'limit' => $schema->integer()
                ->description('Maximum number of items to return. Defaults to 10, max 25.'),
            'priority_filter' => $schema->string()
                ->enum(['all', 'critical', 'high'])
                ->description('Optional priority filter for focus items.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function briefingPayload(string $priorityFilter): array
    {
        $briefing = $this->synthesisService->getMorningBriefing();

        return [
            'greeting' => $briefing['greeting'],
            'summary' => $briefing['summary'],
            'recommendations' => $briefing['recommendations'],
            'top_priorities' => $this->filteredItems($priorityFilter, 5),
            'priority_filter' => $priorityFilter,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function filteredItems(string $priorityFilter, int $limit): array
    {
        $items = $this->synthesisService->getHumanRequiredItems();

        if ($priorityFilter !== 'all') {
            $items = array_values(array_filter($items, fn (array $item): bool => $priorityFilter === 'critical'
                ? ($item['priority'] ?? 'medium') === 'critical'
                : in_array($item['priority'] ?? 'medium', ['critical', 'high'], true)
            ));
        }

        return array_slice($items, 0, $limit);
    }
}
