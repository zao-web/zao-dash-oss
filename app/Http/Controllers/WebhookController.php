<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\Agents\AgentExecutor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles external webhook triggers for agents.
 *
 * Webhooks allow external services (Zapier, GitHub, Slack) to trigger
 * agent execution without authentication - instead using signed tokens.
 */
class WebhookController extends Controller
{
    public function __construct(
        protected AgentExecutor $executor
    ) {}

    /**
     * Trigger an agent via webhook.
     *
     * POST /webhooks/agents/{agent:slug}
     *
     * Headers:
     *   X-Webhook-Token: {token} - Required, validates request
     *   X-Webhook-Source: {source} - Optional, for logging
     *
     * Body:
     *   prompt: string - The task/prompt for the agent
     *   context: object - Additional context data
     *   metadata: object - Custom metadata for tracking
     */
    public function trigger(Request $request, Agent $agent)
    {
        // Validate webhook token
        $token = $request->header('X-Webhook-Token');
        if (! $this->validateToken($agent, $token)) {
            Log::warning('Invalid webhook token', [
                'agent' => $agent->slug,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'Invalid webhook token'], 401);
        }

        // Validate IP allowlist (if configured)
        if (! $this->validateIpAllowlist($agent, $request->ip())) {
            Log::warning('Webhook IP not in allowlist', [
                'agent' => $agent->slug,
                'ip' => $request->ip(),
                'allowed_ips' => $agent->webhook_allowed_ips,
            ]);

            return response()->json(['error' => 'IP address not allowed'], 403);
        }

        // Check agent can be triggered
        if ($agent->status !== 'active') {
            return response()->json([
                'error' => 'Agent is not active',
                'status' => $agent->status,
            ], 400);
        }

        $validated = $request->validate([
            'prompt' => 'nullable|string|max:10000',
            'context' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        try {
            $run = $this->executor->execute(
                agent: $agent,
                config: [
                    'prompt' => $validated['prompt'] ?? '',
                    'context' => $validated['context'] ?? [],
                ],
                invocationSource: AgentRun::SOURCE_WEBHOOK,
                invokedBy: 'webhook:'.($request->header('X-Webhook-Source') ?? 'unknown'),
                triggerMetadata: [
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'source' => $request->header('X-Webhook-Source'),
                    'custom' => $validated['metadata'] ?? [],
                ],
            );

            return response()->json([
                'success' => true,
                'run_id' => $run->id,
                'status' => $run->status,
                'requires_approval' => $run->status === 'pending_approval',
                'status_url' => route('webhooks.status', ['runId' => $run->id]),
            ], 202);

        } catch (\Exception $e) {
            Log::error('Webhook trigger failed', [
                'agent' => $agent->slug,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to trigger agent',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check status of a webhook-triggered run.
     */
    public function status(Request $request, int $runId)
    {
        $run = AgentRun::with('agent')->findOrFail($runId);

        // Basic output for completed runs
        $output = null;
        if ($run->status === 'completed' && $run->output) {
            $output = $run->output;
        }

        return response()->json([
            'run_id' => $run->id,
            'agent' => $run->agent->slug,
            'status' => $run->status,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'duration_ms' => $run->duration_ms,
            'output' => $output,
            'error' => $run->error_message,
        ]);
    }

    /**
     * Regenerate webhook token for an agent.
     */
    public function regenerateToken(Request $request, Agent $agent)
    {
        $newToken = Str::random(64);

        $agent->update([
            'webhook_token' => hash('sha256', $newToken),
            'webhook_enabled' => true,
        ]);

        Log::info('Webhook token regenerated', ['agent' => $agent->slug]);

        return response()->json([
            'token' => $newToken,
            'webhook_url' => route('webhooks.trigger', ['agent' => $agent->slug]),
            'message' => 'Store this token securely - it will not be shown again',
        ]);
    }

    /**
     * Disable webhooks for an agent.
     */
    public function disable(Agent $agent)
    {
        $agent->update([
            'webhook_enabled' => false,
            'webhook_token' => null,
        ]);

        return response()->json([
            'message' => 'Webhook disabled for agent',
            'agent' => $agent->slug,
        ]);
    }

    /**
     * Get webhook configuration for an agent.
     */
    public function config(Agent $agent)
    {
        return response()->json([
            'agent' => $agent->slug,
            'webhook_enabled' => $agent->webhook_enabled ?? false,
            'webhook_url' => route('webhooks.trigger', ['agent' => $agent->slug]),
            'has_token' => ! empty($agent->webhook_token),
            'allowed_ips' => $agent->webhook_allowed_ips ?? [],
            'has_ip_restriction' => ! empty($agent->webhook_allowed_ips),
        ]);
    }

    /**
     * Validate webhook token.
     */
    protected function validateToken(Agent $agent, ?string $token): bool
    {
        if (empty($token) || empty($agent->webhook_token)) {
            return false;
        }

        return hash_equals($agent->webhook_token, hash('sha256', $token));
    }

    /**
     * Validate IP against allowlist.
     *
     * If no allowlist is configured, all IPs are allowed.
     * Supports both exact IPs and CIDR notation.
     */
    protected function validateIpAllowlist(Agent $agent, string $ip): bool
    {
        $allowedIps = $agent->webhook_allowed_ips ?? [];

        // If no allowlist configured, allow all
        if (empty($allowedIps)) {
            return true;
        }

        foreach ($allowedIps as $allowed) {
            // Exact match
            if ($allowed === $ip) {
                return true;
            }

            // CIDR notation check
            if (str_contains($allowed, '/')) {
                if ($this->ipInCidr($ip, $allowed)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if an IP is within a CIDR range.
     */
    protected function ipInCidr(string $ip, string $cidr): bool
    {
        [$range, $bits] = explode('/', $cidr, 2);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            // IPv4
            $ipLong = ip2long($ip);
            $rangeLong = ip2long($range);
            $mask = -1 << (32 - (int) $bits);

            return ($ipLong & $mask) === ($rangeLong & $mask);
        }

        // IPv6 - simplified comparison
        return inet_pton($ip) === inet_pton($range);
    }

    /**
     * Update IP allowlist for an agent.
     */
    public function updateAllowlist(Request $request, Agent $agent)
    {
        $validated = $request->validate([
            'allowed_ips' => 'nullable|array',
            'allowed_ips.*' => 'required|string',
        ]);

        // Validate IP formats
        $ips = $validated['allowed_ips'] ?? [];
        foreach ($ips as $ip) {
            if (str_contains($ip, '/')) {
                // CIDR notation
                [$range, $bits] = explode('/', $ip, 2);
                if (! filter_var($range, FILTER_VALIDATE_IP)) {
                    return response()->json([
                        'error' => "Invalid IP in CIDR: {$ip}",
                    ], 422);
                }
            } else {
                if (! filter_var($ip, FILTER_VALIDATE_IP)) {
                    return response()->json([
                        'error' => "Invalid IP format: {$ip}",
                    ], 422);
                }
            }
        }

        $agent->update([
            'webhook_allowed_ips' => $ips,
        ]);

        Log::info('Webhook IP allowlist updated', [
            'agent' => $agent->slug,
            'allowed_ips' => $ips,
        ]);

        return response()->json([
            'message' => 'IP allowlist updated',
            'allowed_ips' => $ips,
        ]);
    }
}
