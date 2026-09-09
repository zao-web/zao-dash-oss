<?php

namespace App\Services\Slack;

use App\Models\SlackThreadContext;
use App\Services\AI\AnthropicService;

class SlackControlPlaneService
{
    public function __construct(
        protected AnthropicService $anthropic,
        protected SlackMcpToolBridge $toolBridge,
    ) {}

    /**
     * @return array{success: bool, message?: string, error?: string, raw?: array<string, mixed>}
     */
    public function handle(SlackThreadContext $context, string $userMessage): array
    {
        if (! $this->anthropic->isConfigured()) {
            return [
                'success' => false,
                'error' => 'Anthropic/Claude is not configured for Slack control plane requests.',
            ];
        }

        $channel = $context->channel;
        $workspace = $channel->workspace;
        $slackUserId = (string) ($context->context_data['slack_user_id'] ?? $context->context_data['user_id'] ?? '');
        $watchedChannels = [];

        if ($channel->is_dm && $slackUserId !== '') {
            $watchlistResult = $this->toolBridge->execute('list-slack-watchlist', [
                'workspace_id' => $workspace->workspace_id,
                'slack_user_id' => $slackUserId,
            ]);

            if (! isset($watchlistResult['error'])) {
                $watchedChannels = $watchlistResult['items'] ?? [];
            }
        }

        $completedActions = $this->formatCompletedActions($context);

        $prompt = <<<PROMPT
User request: {$userMessage}

Current Slack thread:
- workspace_id: {$workspace->workspace_id}
- channel_id: {$channel->channel_id}
- thread_ts: {$context->thread_ts}
- is_dm: {$channel->is_dm}

Recent thread history (most recent last):
{$this->formatHistory($context)}

Actions already completed in this thread:
{$completedActions}

Watched Slack channels for this user:
{$this->formatWatchedChannels($watchedChannels)}
PROMPT;

        try {
            $result = $this->anthropic->messageWithTools(
                prompt: $prompt,
                systemPrompt: $this->systemPrompt(),
                tools: $this->toolBridge->toolsForAssistant(),
                toolExecutor: fn (string $toolName, array $arguments): array => $this->toolBridge->execute($toolName, $arguments),
                model: 'sonnet',
                maxIterations: 4,
                timeout: 90,
            );

            $message = $this->extractText($result['final_response'] ?? null);

            if (! $message) {
                $message = 'I inspected the dashboard state, but I did not produce a usable Slack reply. Please restate the request more explicitly.';
            }

            return [
                'success' => true,
                'message' => trim($message),
                'raw' => $result,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function systemPrompt(): string
    {
        return <<<'PROMPT'
You are Zao Dash's Slack control plane.

Your job is to make the agency dashboard feel native inside Slack:
- inspect the current Slack channel's operating context
- answer operational questions about clients, projects, tasks, integrations, website work, and agent runs
- answer prioritization questions like "what should I work on today?" and "what needs my attention?"
- surface pending approvals and act on them when the user is explicit
- inspect and sync GitHub, ClickUp/project management, Harvest, and WordPress integrations for the current channel context
- turn approved SOWs or proposals into provisioned client/project work when the user shares Google Doc links or pasted scope text
- perform requested dashboard actions when the user is explicit
- keep the user operating from Slack instead of sending them back to the web app unless a UI-only step is necessary

CRITICAL DATA INTEGRITY RULES (violating these causes real financial harm):
- NEVER fabricate, guess, or approximate record values — no IDs, amounts, dates, names, totals, or counts from memory or inference. Every data point you state MUST come from a tool call in this conversation turn.
- Before referencing ANY record (invoice, task, client, project, lead, contact), you MUST call the appropriate list/get tool FIRST. No exceptions. If you haven't called list-invoices, you cannot mention invoice amounts. If you haven't called get-client, you cannot state client details.
- NEVER invent tools, APIs, or services that don't exist. You can ONLY use tools from your provided tool list. If a capability is missing, say "I don't have a tool for that" — do not hallucinate a workaround.
- When creating or modifying records (invoices, clients, contacts, tasks), double-check that every value comes from the user's explicit request or a prior tool call result. Never fill in values you inferred from conversation context or document text.
- If you are unsure about any data point, call a tool to verify rather than guessing.
- When a tool returns an error, report the EXACT error message to the user. Do not rephrase, diagnose, or speculate about causes. Say: "The [tool-name] tool returned an error: [exact error message]". Do not mention "org resolution", "organization UUID", or any concept not in the error message.
- For email/Gmail searches: use `search-gmail-live` to search Gmail directly via API, or `search-emails` to search locally synced emails. Use `trigger-rfp-scan` to parse emails for RFP opportunities.

Operating style:
- Think like a compact autonomous ops program, not a chatty assistant.
- If the conversation is happening in a DM, treat the DM as the command surface and use the current thread only as the conversation wrapper; when the user references a client, project, or Slack channel, resolve that context with tools before answering.
- If the user is asking about their tracked channels or wants to save/remove channels from that private set, use `list-slack-watchlist` and `manage-slack-watchlist`.
- When Slack context matters, call `get-channel-operations-context` early using the current workspace_id and channel_id.
- When the user asks about priorities, focus, or what to work on, call `get-focus`.
- When the user shares SOW/proposal docs and asks to spin up work, call `import-sow`. Pass the current workspace/channel IDs if the user wants this Slack channel linked to the new client/project context.
- Use search/list/get tools to disambiguate before taking write actions.
- If the user asks to link the current channel to a client or project, use `link-slack-context`.
- If you make changes, state exactly what changed — with values from the tool response, not paraphrased.
- If something cannot be done safely with the available tools, say clearly: "I don't have a tool for [specific action]. You'll need to do this in the dashboard." Do not fabricate alternative approaches.

Slack response rules:
- Keep responses concise and operational.
- Use short bullets only when they improve scanability.
- Include IDs or names when referencing records you changed or found — always from tool results, never from memory.
PROMPT;
    }

    protected function formatHistory(SlackThreadContext $context): string
    {
        return collect($context->getRecentHistory(12))
            ->map(fn (array $entry): string => sprintf(
                '[%s @ %s] %s',
                $entry['role'] ?? 'unknown',
                isset($entry['timestamp']) ? \Carbon\Carbon::parse($entry['timestamp'])->diffForHumans() : '?',
                $entry['content'] ?? ''
            ))
            ->implode("\n");
    }

    /**
     * @param  array<int, array<string, mixed>>  $watchedChannels
     */
    protected function formatWatchedChannels(array $watchedChannels): string
    {
        if ($watchedChannels === []) {
            return '- None yet. The user has not saved any channels to the watchlist.';
        }

        return collect($watchedChannels)
            ->map(function (array $item): string {
                $parts = [];

                $label = $item['label'] ?? $item['channel_name'] ?? 'Unknown channel';
                $parts[] = $label;

                if (! empty($item['client']['name'])) {
                    $parts[] = 'client: '.$item['client']['name'];
                }

                if (! empty($item['project']['name'])) {
                    $parts[] = 'project: '.$item['project']['name'];
                }

                if (! empty($item['channel_id'])) {
                    $parts[] = 'channel_id: '.$item['channel_id'];
                }

                return '- '.implode(' | ', $parts);
            })
            ->implode("\n");
    }

    protected function formatCompletedActions(SlackThreadContext $context): string
    {
        $completed = $context->completed_actions ?? [];

        if (empty($completed)) {
            return '- None yet.';
        }

        return collect($completed)
            ->map(function (array $action): string {
                $type = $action['type'] ?? 'unknown';
                $success = ($action['result']['success'] ?? false) ? 'success' : 'failed';
                $message = $action['result']['message'] ?? '';

                return "- {$type}: {$success}".($message ? " — {$message}" : '');
            })
            ->implode("\n");
    }

    /**
     * @param  array<string, mixed>|null  $response
     */
    protected function extractText(?array $response): ?string
    {
        if (! $response) {
            return null;
        }

        $content = $response['content'] ?? [];

        $text = collect($content)
            ->filter(fn ($block) => ($block['type'] ?? null) === 'text')
            ->map(fn ($block) => $block['text'] ?? '')
            ->filter()
            ->implode("\n");

        return $text !== '' ? $text : null;
    }
}
