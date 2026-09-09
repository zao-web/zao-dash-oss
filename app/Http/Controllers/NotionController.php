<?php

namespace App\Http\Controllers;

use App\Jobs\SyncNotionJob;
use App\Models\NotionConnection;
use App\Models\NotionPage;
use App\Services\Notion\NotionApiService;
use App\Services\Notion\NotionOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NotionController extends Controller
{
    public function __construct(
        protected NotionOAuthService $oauth,
        protected NotionApiService $api
    ) {}

    public function redirect(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('notion_oauth_state', $state);

        return redirect($this->oauth->getAuthorizationUrl($state));
    }

    public function callback(Request $request)
    {
        if ($request->session()->get('notion_oauth_state') !== $request->state) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Invalid OAuth state');
        }

        try {
            $tokenData = $this->oauth->exchangeCodeForToken($request->code);
            $connection = $this->oauth->storeConnection($tokenData, auth()->id());

            // Dispatch initial sync
            SyncNotionJob::dispatch($connection->id)->onQueue('sync');

            return redirect()->route('settings.integrations')
                ->with('success', "Connected to Notion workspace: {$tokenData['workspace_name']}. Syncing your pages now...");
        } catch (\Exception $e) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Failed to connect to Notion: '.$e->getMessage());
        }
    }

    public function disconnect(NotionConnection $connection)
    {
        $name = $connection->workspace_name;
        $connection->delete();

        return back()->with('success', "Disconnected from Notion workspace: {$name}");
    }

    public function sync(NotionConnection $connection)
    {
        try {
            $count = $this->api->syncAllPages($connection);

            return back()->with('success', "Synced {$count} pages from Notion");
        } catch (\Exception $e) {
            return back()->with('error', 'Sync failed: '.$e->getMessage());
        }
    }

    public function syncDatabase(NotionPage $database)
    {
        if (! $database->is_database) {
            return back()->with('error', 'This is not a database');
        }

        try {
            $connection = $database->notionConnection;
            $count = $this->api->syncDatabaseItems($connection, $database);

            return back()->with('success', "Synced {$count} items from database");
        } catch (\Exception $e) {
            return back()->with('error', 'Sync failed: '.$e->getMessage());
        }
    }

    public function pages(NotionConnection $connection)
    {
        return response()->json([
            'pages' => $connection->pages()->where('is_database', false)->get(),
            'databases' => $connection->pages()->where('is_database', true)->get(),
        ]);
    }
}
