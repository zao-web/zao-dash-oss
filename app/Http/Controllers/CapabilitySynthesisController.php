<?php

namespace App\Http\Controllers;

use App\Services\CapabilitySynthesisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapabilitySynthesisController extends Controller
{
    public function __construct(
        protected CapabilitySynthesisService $service
    ) {}

    /**
     * Get items that require human attention.
     * "What are the things only I can do?"
     */
    public function humanRequired(Request $request): JsonResponse
    {
        $items = $this->service->getHumanRequiredItems($request->user()?->id);

        return response()->json([
            'items' => $items,
            'count' => count($items),
        ]);
    }

    /**
     * Get the morning briefing.
     * "What should I focus on today?"
     */
    public function briefing(): JsonResponse
    {
        $briefing = $this->service->getMorningBriefing();

        return response()->json($briefing);
    }

    /**
     * Get system capability summary.
     * "What can the system do automatically?"
     */
    public function capabilities(): JsonResponse
    {
        $summary = $this->service->getCapabilitySummary();

        return response()->json($summary);
    }

    /**
     * Get automation gaps.
     * "What could we automate but haven't?"
     */
    public function gaps(): JsonResponse
    {
        $gaps = $this->service->getAutomationGaps();

        return response()->json(['gaps' => $gaps]);
    }

    /**
     * Combined endpoint for the command palette.
     * Returns a natural language summary.
     */
    public function focusQuery(Request $request): JsonResponse
    {
        $briefing = $this->service->getMorningBriefing();
        $items = $this->service->getHumanRequiredItems($request->user()?->id);

        $offset = (int) $request->input('offset', 0);
        $limit = min((int) $request->input('limit', 10), 50);

        $greeting = $briefing['greeting'];
        $summary = $briefing['summary'];

        $response = "{$greeting}! ";

        if ($summary['total_items'] === 0) {
            $response .= "You're all caught up - no items require your attention right now. Great time to focus on strategic work.";
        } else {
            $response .= "You have {$summary['total_items']} item(s) that need your attention. ";

            if ($summary['critical'] > 0) {
                $response .= "**{$summary['critical']} critical** (agents waiting for approval). ";
            }

            if ($summary['high'] > 0) {
                $response .= "{$summary['high']} high priority. ";
            }

            $recommendations = $briefing['recommendations'];
            if (! empty($recommendations)) {
                $response .= "\n\n**Recommendations:**\n";
                foreach ($recommendations as $rec) {
                    $response .= "- {$rec}\n";
                }
            }
        }

        $paginatedItems = array_slice($items, $offset, $limit);

        return response()->json([
            'summary' => $response,
            'briefing' => $briefing,
            'items' => $paginatedItems,
            'total' => count($items),
            'offset' => $offset,
            'limit' => $limit,
            'has_more' => ($offset + $limit) < count($items),
        ]);
    }

    public function dismissItem(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string',
            'id' => 'required',
        ]);

        $type = $validated['type'];
        $id = $validated['id'];

        $dismissed = false;

        switch ($type) {
            case 'client_health':
                $dismissed = $this->dismissClientHealth($id, $request->user()?->id);
                break;
            case 'lead_followup':
                $dismissed = $this->dismissLeadFollowup($id, $request->user()?->id);
                break;
            case 'slack_action_item':
                $dismissed = $this->dismissSlackItem($id);
                break;
            case 'pr_review':
                $dismissed = $this->dismissPrReview($id, $request->user()?->id);
                break;
            default:
                $dismissed = $this->dismissGenericItem($type, $id, $request->user()?->id);
        }

        return response()->json([
            'success' => $dismissed,
            'type' => $type,
            'id' => $id,
        ]);
    }

    protected function dismissClientHealth(int $clientId, ?int $userId): bool
    {
        $key = "dismissed_client_health_{$userId}_{$clientId}";
        cache()->put($key, true, now()->addDays(7));

        return true;
    }

    protected function dismissLeadFollowup(int $leadId, ?int $userId): bool
    {
        $key = "dismissed_lead_followup_{$userId}_{$leadId}";
        cache()->put($key, true, now()->addDays(3));

        return true;
    }

    protected function dismissSlackItem(int $notificationId): bool
    {
        $notification = \App\Models\Notification::find($notificationId);
        if ($notification) {
            $notification->update(['dismissed_at' => now()]);

            return true;
        }

        return false;
    }

    protected function dismissPrReview(int $prId, ?int $userId): bool
    {
        $key = "dismissed_pr_review_{$userId}_{$prId}";
        cache()->put($key, true, now()->addDays(1));

        return true;
    }

    protected function dismissGenericItem(string $type, $id, ?int $userId): bool
    {
        $key = "dismissed_{$type}_{$userId}_{$id}";
        cache()->put($key, true, now()->addDays(1));

        return true;
    }
}
