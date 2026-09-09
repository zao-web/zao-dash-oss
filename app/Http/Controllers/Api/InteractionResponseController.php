<?php

namespace App\Http\Controllers\Api;

use App\Events\InteractionResponseReceived;
use App\Http\Controllers\Controller;
use App\Jobs\RunInteractiveAgentJob;
use App\Models\InteractionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class InteractionResponseController extends Controller
{
    /**
     * Submit a response to an interaction request.
     *
     * Uses row-level locking to prevent race conditions when multiple
     * tabs/users attempt to respond simultaneously.
     *
     * Authorization: Protected by EnsureInternalUser middleware in routes.
     * All internal team members can respond to any pending interaction.
     */
    public function respond(Request $request, InteractionRequest $interaction): JsonResponse
    {
        // Verify interaction is still actionable
        if ($interaction->isResponded()) {
            return response()->json([
                'message' => 'This interaction has already been responded to',
                'interaction' => $this->formatInteraction($interaction),
            ], 409);
        }

        if ($interaction->isExpired()) {
            return response()->json([
                'message' => 'This interaction has expired',
                'interaction' => $this->formatInteraction($interaction),
            ], 410);
        }

        $validated = $request->validate([
            'response' => ['required', 'string', 'max:10000'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        $idempotencyKey = $validated['idempotency_key'] ?? null;

        // Check for idempotent request (already processed)
        if ($idempotencyKey && $interaction->idempotency_key === $idempotencyKey) {
            return response()->json([
                'message' => 'Response already recorded',
                'interaction' => $this->formatInteraction($interaction),
                'idempotent' => true,
            ]);
        }

        try {
            $result = DB::transaction(function () use ($interaction, $validated, $idempotencyKey, $request) {
                // Lock the row to prevent concurrent modifications
                $lockedInteraction = InteractionRequest::lockForUpdate()->find($interaction->id);

                if (! $lockedInteraction) {
                    throw ValidationException::withMessages([
                        'interaction' => ['Interaction request not found.'],
                    ]);
                }

                // Check if already responded
                if ($lockedInteraction->isResponded()) {
                    return [
                        'already_responded' => true,
                        'interaction' => $lockedInteraction,
                    ];
                }

                // Check if expired
                if ($lockedInteraction->isExpired()) {
                    throw ValidationException::withMessages([
                        'interaction' => ['This interaction request has expired.'],
                    ]);
                }

                // Record the response
                $lockedInteraction->update([
                    'response' => $validated['response'],
                    'responded_at' => now(),
                    'responded_via' => InteractionRequest::VIA_DASHBOARD,
                    'responded_by_id' => $request->user()?->id,
                    'idempotency_key' => $idempotencyKey,
                ]);

                return [
                    'already_responded' => false,
                    'interaction' => $lockedInteraction->fresh(),
                ];
            });

            $updatedInteraction = $result['interaction'];

            // If already responded, return early
            if ($result['already_responded']) {
                return response()->json([
                    'message' => 'Response already recorded by another user',
                    'interaction' => $this->formatInteraction($updatedInteraction),
                    'already_responded' => true,
                ]);
            }

            // Broadcast that response was received (for multi-tab coordination)
            InteractionResponseReceived::dispatch($updatedInteraction);

            // Dispatch job to resume the agent with the response
            $this->dispatchResumeJob($updatedInteraction);

            Log::info('Interaction response recorded', [
                'interaction_id' => $updatedInteraction->id,
                'run_id' => $updatedInteraction->agent_run_id,
                'responded_by' => $request->user()?->id,
            ]);

            return response()->json([
                'message' => 'Response recorded successfully',
                'interaction' => $this->formatInteraction($updatedInteraction),
            ]);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Failed to record interaction response', [
                'interaction_id' => $interaction->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to record response',
                'error' => 'An unexpected error occurred',
            ], 500);
        }
    }

    /**
     * Get the current state of an interaction request.
     *
     * Authorization: Protected by EnsureInternalUser middleware in routes.
     */
    public function show(InteractionRequest $interaction): JsonResponse
    {
        return response()->json([
            'interaction' => $this->formatInteraction($interaction),
        ]);
    }

    /**
     * List pending interactions for the team.
     *
     * Authorization: Protected by EnsureInternalUser middleware in routes.
     * All internal team members can see all pending interactions to enable
     * collaborative response handling.
     */
    public function pending(Request $request): JsonResponse
    {
        $interactions = InteractionRequest::query()
            ->pending()
            ->with(['agentRun.agent'])
            ->orderBy('expires_at', 'asc')
            ->limit(10)
            ->get();

        return response()->json([
            'interactions' => $interactions->map(fn ($i) => $this->formatInteraction($i)),
        ]);
    }

    /**
     * Dispatch job to resume the agent run with the response.
     */
    protected function dispatchResumeJob(InteractionRequest $interaction): void
    {
        $agentRun = $interaction->agentRun;

        if (! $agentRun || ! $agentRun->canResume()) {
            Log::warning('Cannot resume agent run', [
                'interaction_id' => $interaction->id,
                'run_id' => $agentRun?->id,
                'can_resume' => $agentRun?->canResume(),
            ]);

            return;
        }

        RunInteractiveAgentJob::dispatch(
            $agentRun,
            $agentRun->checkpoint['config'] ?? [],
            $interaction->response
        );

        Log::info('Dispatched resume job', [
            'interaction_id' => $interaction->id,
            'run_id' => $agentRun->id,
        ]);
    }

    /**
     * Format interaction for JSON response.
     */
    protected function formatInteraction(InteractionRequest $interaction): array
    {
        $interaction->loadMissing(['agentRun.agent', 'respondedBy']);

        return [
            'id' => $interaction->id,
            'run_id' => $interaction->agent_run_id,
            'agent_id' => $interaction->agentRun?->agent_id,
            'agent_name' => $interaction->agentRun?->agent?->name,
            'agent_slug' => $interaction->agentRun?->agent?->slug,
            'question_type' => $interaction->question_type,
            'question' => $interaction->question_content,
            'options' => $interaction->options,
            'context' => $interaction->context,
            'response' => $interaction->response,
            'responded_at' => $interaction->responded_at?->toIso8601String(),
            'responded_via' => $interaction->responded_via,
            'responded_by' => $interaction->respondedBy ? [
                'id' => $interaction->respondedBy->id,
                'name' => $interaction->respondedBy->name,
            ] : null,
            'expires_at' => $interaction->expires_at->toIso8601String(),
            'is_expired' => $interaction->isExpired(),
            'is_responded' => $interaction->isResponded(),
            'is_pending' => $interaction->isPending(),
            'remaining_seconds' => $interaction->remaining_time,
            'created_at' => $interaction->created_at->toIso8601String(),
        ];
    }
}
