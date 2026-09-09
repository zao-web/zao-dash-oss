<?php

namespace App\Mcp\Tools;

use App\Events\InteractionResponseReceived;
use App\Jobs\RunInteractiveAgentJob;
use App\Models\InteractionRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class RespondToInteractionTool extends Tool
{
    protected string $name = 'respond-to-interaction';

    protected string $title = 'Respond to Interaction';

    protected string $description = 'Submit a response to a pending agent interaction request.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'interaction_id' => 'required|integer',
            'response' => 'required|string',
        ]);

        $interactionId = $request->get('interaction_id');
        $response = $request->get('response');

        try {
            $result = DB::transaction(function () use ($interactionId, $response) {
                $interaction = InteractionRequest::lockForUpdate()->find($interactionId);

                if (! $interaction) {
                    return ['error' => 'not_found'];
                }

                if ($interaction->isResponded()) {
                    return [
                        'already_responded' => true,
                        'interaction' => $interaction,
                    ];
                }

                if ($interaction->isExpired()) {
                    return ['expired' => true];
                }

                // Update the interaction with the response
                $interaction->update([
                    'response' => $response,
                    'responded_at' => now(),
                    'responded_via' => InteractionRequest::VIA_MCP,
                ]);

                // Resume the agent run
                RunInteractiveAgentJob::dispatch($interaction->agentRun, $interaction);

                // Broadcast the response event
                broadcast(new InteractionResponseReceived($interaction));

                return ['success' => true, 'interaction' => $interaction];
            });

            if (isset($result['error'])) {
                return Response::structured([
                    'success' => false,
                    'message' => 'Interaction request not found.',
                ]);
            }

            if (isset($result['expired'])) {
                return Response::structured([
                    'success' => false,
                    'message' => 'Interaction request has expired.',
                ]);
            }

            if (isset($result['already_responded'])) {
                return Response::structured([
                    'success' => false,
                    'already_responded' => true,
                    'message' => 'This interaction was already responded to.',
                    'responded_at' => $result['interaction']->responded_at?->toIso8601String(),
                    'responded_via' => $result['interaction']->responded_via,
                ]);
            }

            return Response::structured([
                'success' => true,
                'interaction_id' => $interactionId,
                'run_id' => $result['interaction']->agent_run_id,
                'message' => 'Response submitted. The agent will continue with your input.',
            ]);

        } catch (\Exception $e) {
            return Response::structured([
                'success' => false,
                'message' => 'Failed to submit response: '.$e->getMessage(),
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'interaction_id' => $schema->integer()
                ->description('The ID of the interaction request to respond to'),
            'response' => $schema->string()
                ->description('The response to submit. For select/confirm types, use the option label or "yes"/"no".'),
        ];
    }
}
