<?php

namespace App\Http\Controllers;

use App\Jobs\SyncGoogleDriveJob;
use App\Jobs\SyncGSuiteJob;
use App\Services\Google\CalendarService;
use App\Services\Google\DriveService;
use App\Services\Google\GmailService;
use App\Services\Google\GoogleOAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GoogleIntegrationController extends Controller
{
    public function __construct(
        private GoogleOAuthService $oauth,
        private GmailService $gmail,
        private CalendarService $calendar,
        private DriveService $drive
    ) {}

    public function status(Request $request)
    {
        $user = $request->user();
        $credential = $user->googleCredential;

        return response()->json([
            'connected' => $credential !== null,
            'email' => $credential?->email,
            'scopes' => $credential?->scopes ?? [],
            'gmail_watch_active' => $credential?->watch_expiration?->isFuture() ?? false,
            'calendar_watch_active' => $credential?->calendar_watch_expiration?->isFuture() ?? false,
        ]);
    }

    public function redirect(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect($this->oauth->getAuthUrl($state));
    }

    public function callback(Request $request)
    {
        $storedState = $request->session()->pull('google_oauth_state');

        if ($request->state !== $storedState) {
            return redirect('/settings/integrations')
                ->with('error', 'Invalid OAuth state. Please try again.');
        }

        if ($request->has('error')) {
            return redirect('/settings/integrations')
                ->with('error', 'Google authorization was denied: '.$request->error);
        }

        try {
            $tokens = $this->oauth->exchangeCodeForTokens($request->code);
            $credential = $this->oauth->storeCredentials($request->user(), $tokens);
        } catch (\Exception $e) {
            return redirect('/settings/integrations')
                ->with('error', 'Failed to connect Google account: '.$e->getMessage());
        }

        // Watch setup is best-effort — don't let it block a successful connection
        try {
            $this->gmail->watchInbox($request->user());
            $this->calendar->watchCalendar($request->user());
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('Google watch setup failed after connect', [
                'error' => $e->getMessage(),
            ]);
        }

        // Dispatch initial sync jobs
        SyncGSuiteJob::dispatch($credential->id)->onQueue('sync');
        SyncGoogleDriveJob::dispatch($credential->id)->onQueue('sync');

        return redirect('/settings/integrations')
            ->with('success', 'Google account connected! Syncing your data now...');
    }

    public function disconnect(Request $request)
    {
        $user = $request->user();
        $credential = $user->googleCredential;

        if ($credential) {
            try {
                $this->gmail->stopWatch($user);
            } catch (\Exception $e) {
                // Ignore errors when stopping watch
            }

            $this->oauth->revokeAccess($credential);
        }

        return redirect('/settings/integrations')
            ->with('success', 'Google account disconnected.');
    }

    public function syncEmails(Request $request)
    {
        $user = $request->user();

        if (! $this->oauth->hasValidCredentials($user)) {
            return response()->json(['error' => 'Google account not connected'], 401);
        }

        try {
            $messages = $this->gmail->listMessages($user, [
                'maxResults' => 50,
            ]);

            $synced = 0;
            foreach ($messages['messages'] ?? [] as $message) {
                $this->gmail->syncAndStoreEmail($user, $message['id']);
                $synced++;
            }

            return response()->json([
                'success' => true,
                'synced' => $synced,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function syncCalendar(Request $request)
    {
        $user = $request->user();

        if (! $this->oauth->hasValidCredentials($user)) {
            return response()->json(['error' => 'Google account not connected'], 401);
        }

        try {
            $count = $this->calendar->syncEvents($user);

            return response()->json([
                'success' => true,
                'synced' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function discoverDocuments(Request $request)
    {
        $user = $request->user();

        if (! $this->oauth->hasValidCredentials($user)) {
            return response()->json(['error' => 'Google account not connected'], 401);
        }

        try {
            $files = $this->drive->discoverDocuments($user);
            $indexed = 0;

            foreach ($files as $file) {
                $this->drive->indexDocument($user, $file);
                $indexed++;
            }

            return response()->json([
                'success' => true,
                'discovered' => count($files),
                'indexed' => $indexed,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function searchDrive(Request $request)
    {
        $user = $request->user();

        if (! $this->oauth->hasValidCredentials($user)) {
            return response()->json(['error' => 'Google account not connected'], 401);
        }

        $query = $request->input('q', '');
        if (empty($query)) {
            return response()->json(['files' => []]);
        }

        try {
            $results = $this->drive->searchFiles($user, "name contains '{$query}'");

            return response()->json([
                'files' => $results['files'] ?? [],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function upcomingMeetings(Request $request)
    {
        $user = $request->user();

        if (! $this->oauth->hasValidCredentials($user)) {
            return response()->json(['error' => 'Google account not connected'], 401);
        }

        try {
            $meetings = $this->calendar->getUpcomingClientMeetings($user);

            return response()->json([
                'meetings' => $meetings,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function renewWatches(Request $request)
    {
        $user = $request->user();
        $credential = $user->googleCredential;

        if (! $credential) {
            return response()->json(['error' => 'Google account not connected'], 401);
        }

        $renewed = [];

        try {
            if ($credential->needsWatchRenewal()) {
                $this->gmail->watchInbox($user);
                $renewed[] = 'gmail';
            }

            if ($credential->needsCalendarWatchRenewal()) {
                $this->calendar->watchCalendar($user);
                $renewed[] = 'calendar';
            }

            return response()->json([
                'success' => true,
                'renewed' => $renewed,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
