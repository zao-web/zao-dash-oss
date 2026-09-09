<?php

namespace App\Http\Controllers;

use App\Jobs\SyncWordPressJob;
use App\Models\ContentSuggestion;
use App\Models\WordPressSite;
use App\Services\WordPress\WordPressMcpService;
use Illuminate\Http\Request;

class WordPressController extends Controller
{
    public function __construct(
        protected WordPressMcpService $service
    ) {}

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'url' => 'required|url',
            'username' => 'required|string',
            'app_password' => 'required|string',
            'mcp_enabled' => 'boolean',
        ]);

        $site = WordPressSite::create([
            'name' => $validated['name'],
            'url' => rtrim($validated['url'], '/'),
            'username' => $validated['username'],
            'application_password' => $validated['app_password'],
            'mcp_enabled' => $validated['mcp_enabled'] ?? false,
        ]);

        // Test connection
        if (! $this->service->testConnection($site)) {
            $site->delete();

            return back()->with('error', 'Could not connect to WordPress site. Check credentials.');
        }

        $site->update(['last_connected_at' => now()]);

        // Discover MCP capabilities if enabled
        if ($site->mcp_enabled) {
            $this->service->discoverCapabilities($site);
        }

        // Dispatch initial sync
        SyncWordPressJob::dispatch($site->id)->onQueue('sync');

        return redirect()->route('settings.integrations')
            ->with('success', "Connected to WordPress site: {$site->name}. Syncing your posts now...");
    }

    public function disconnect(WordPressSite $site)
    {
        $name = $site->name;
        $site->delete();

        return back()->with('success', "Disconnected WordPress site: {$name}");
    }

    public function sync(WordPressSite $site)
    {
        try {
            $count = $this->service->syncPosts($site);
            $site->update(['last_synced_at' => now()]);

            return back()->with('success', "Synced {$count} posts from {$site->name}");
        } catch (\Exception $e) {
            return back()->with('error', 'Sync failed: '.$e->getMessage());
        }
    }

    public function discoverCapabilities(WordPressSite $site)
    {
        if (! $site->mcp_enabled) {
            return back()->with('error', 'MCP is not enabled for this site');
        }

        try {
            $tools = $this->service->discoverCapabilities($site);

            return back()->with('success', 'Discovered '.count($tools).' MCP tools');
        } catch (\Exception $e) {
            return back()->with('error', 'Discovery failed: '.$e->getMessage());
        }
    }

    public function posts(WordPressSite $site)
    {
        return response()->json($site->posts()->latest('published_at')->get());
    }

    public function suggestions(WordPressSite $site)
    {
        return response()->json(
            ContentSuggestion::where('wordpress_site_id', $site->id)
                ->whereIn('status', ['pending', 'approved'])
                ->latest()
                ->get()
        );
    }

    public function approveSuggestion(ContentSuggestion $suggestion)
    {
        $suggestion->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => auth()->id(),
        ]);

        return back()->with('success', 'Content suggestion approved');
    }

    public function rejectSuggestion(ContentSuggestion $suggestion)
    {
        $suggestion->update(['status' => 'rejected']);

        return back()->with('success', 'Content suggestion rejected');
    }

    public function publishSuggestion(ContentSuggestion $suggestion)
    {
        if ($suggestion->status !== 'approved') {
            return back()->with('error', 'Suggestion must be approved first');
        }

        try {
            $site = $suggestion->wordPressSite;
            $result = $this->service->createPost($site, [
                'title' => $suggestion->title,
                'content' => $suggestion->content,
                'status' => 'draft', // Always create as draft for review
            ]);

            $suggestion->update([
                'status' => 'published',
                'published_post_id' => $result['id'] ?? null,
                'published_at' => now(),
            ]);

            return back()->with('success', 'Content published as draft to WordPress');
        } catch (\Exception $e) {
            return back()->with('error', 'Publish failed: '.$e->getMessage());
        }
    }
}
