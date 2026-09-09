<?php

namespace App\Http\Controllers;

use App\Jobs\SyncSlackJob;
use App\Models\SlackChannel;
use App\Models\SlackWorkspace;
use App\Services\Slack\SlackApiService;
use App\Services\Slack\SlackOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SlackIntegrationController extends Controller
{
    public function __construct(
        private SlackOAuthService $oauth,
        private SlackApiService $api
    ) {}

    public function status()
    {
        $workspaces = SlackWorkspace::with(['channels' => function ($q) {
            $q->where('monitoring_enabled', true);
        }])->get();

        return response()->json([
            'connected' => $workspaces->isNotEmpty(),
            'workspaces' => $workspaces->map(fn ($w) => [
                'id' => $w->id,
                'workspace_id' => $w->workspace_id,
                'name' => $w->workspace_name,
                'is_primary' => $w->is_primary,
                'channels_count' => $w->channels->count(),
            ]),
        ]);
    }

    public function redirect(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('slack_oauth_state', $state);

        return redirect($this->oauth->getAuthUrl($state));
    }

    public function callback(Request $request)
    {
        $storedState = $request->session()->pull('slack_oauth_state');

        if ($request->state !== $storedState) {
            return redirect('/settings/integrations')
                ->with('error', 'Invalid OAuth state. Please try again.');
        }

        if ($request->has('error')) {
            return redirect('/settings/integrations')
                ->with('error', 'Slack authorization was denied: '.$request->error);
        }

        try {
            $tokenData = $this->oauth->exchangeCodeForTokens($request->code);
            $workspace = $this->oauth->storeWorkspace($tokenData);

            // Mark as primary if first workspace
            if (SlackWorkspace::count() === 1) {
                $workspace->update(['is_primary' => true]);
            }

            // Dispatch full sync job (includes channels and messages)
            SyncSlackJob::dispatch($workspace->id)->onQueue('sync');

            return redirect('/settings/integrations')
                ->with('success', "Slack workspace '{$workspace->workspace_name}' connected! Syncing your data now...");
        } catch (\Exception $e) {
            return redirect('/settings/integrations')
                ->with('error', 'Failed to connect Slack: '.$e->getMessage());
        }
    }

    public function disconnect(Request $request, SlackWorkspace $workspace)
    {
        $name = $workspace->workspace_name;
        $this->oauth->revokeAccess($workspace);

        return redirect('/settings/integrations')
            ->with('success', "Slack workspace '{$name}' disconnected.");
    }

    public function syncChannels(Request $request, SlackWorkspace $workspace)
    {
        try {
            $count = $this->api->syncChannels($workspace);

            return response()->json([
                'success' => true,
                'synced' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function listChannels(SlackWorkspace $workspace)
    {
        $channels = $workspace->channels()
            ->withCount('messages')
            ->orderBy('classification')
            ->orderBy('channel_name')
            ->get();

        return response()->json([
            'channels' => $channels->map(fn ($c) => [
                'id' => $c->id,
                'channel_id' => $c->channel_id,
                'name' => $c->channel_name,
                'is_private' => $c->is_private,
                'is_shared' => $c->is_shared,
                'classification' => $c->classification,
                'client_id' => $c->client_id,
                'monitoring_enabled' => $c->monitoring_enabled,
                'messages_count' => $c->messages_count,
                'last_synced_at' => $c->last_synced_at?->diffForHumans(),
            ]),
        ]);
    }

    public function updateChannel(Request $request, SlackChannel $channel)
    {
        $validated = $request->validate([
            'classification' => 'sometimes|string|in:internal,client,project,general',
            'client_id' => 'sometimes|nullable|exists:clients,id',
            'monitoring_enabled' => 'sometimes|boolean',
        ]);

        $channel->update($validated);

        return response()->json(['success' => true, 'channel' => $channel]);
    }

    public function syncMessages(Request $request, SlackChannel $channel)
    {
        $workspace = $channel->workspace;

        try {
            $history = $this->api->getChannelHistory($workspace, $channel);
            $count = 0;

            foreach ($history['messages'] ?? [] as $message) {
                // Skip bot messages and system messages
                if (isset($message['subtype']) && $message['subtype'] !== 'thread_broadcast') {
                    continue;
                }

                $this->api->storeMessage($workspace, $channel, $message);
                $count++;

                // If it's a thread parent, sync the thread
                if (isset($message['reply_count']) && $message['reply_count'] > 0) {
                    $this->api->syncThread($workspace, $channel, $message['ts']);
                }
            }

            $channel->update(['last_synced_at' => now()]);

            return response()->json([
                'success' => true,
                'synced' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function recentMessages(SlackChannel $channel)
    {
        $messages = $channel->messages()
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'user_name' => $m->user_name,
                'user_is_external' => $m->user_is_external,
                'content' => $m->content,
                'has_action_item' => $m->has_action_item,
                'action_item_extracted' => $m->action_item_extracted,
                'is_thread_reply' => $m->isThreadReply(),
                'permalink' => $m->permalink,
                'created_at' => $m->created_at->diffForHumans(),
            ]),
        ]);
    }
}
