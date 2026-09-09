<?php

namespace App\Http\Controllers;

use App\Models\XCredential;
use App\Services\X\XService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class XIntegrationController extends Controller
{
    public function __construct(
        protected XService $xService
    ) {}

    /**
     * Start OAuth flow.
     * Pass account_type=personal or account_type=company.
     * Pass include_bookmarks=1 to request bookmark.read scope.
     */
    public function redirect(Request $request)
    {
        // Store account type in session for callback
        $accountType = $request->input('account_type', XCredential::TYPE_PERSONAL);
        session(['x_account_type' => $accountType]);

        $redirectUri = route('x.callback');
        $includeBookmarks = $request->boolean('include_bookmarks', true); // Default to true now

        $authUrl = $this->xService->getAuthUrl($redirectUri, [], $includeBookmarks);

        return redirect($authUrl);
    }

    public function callback(Request $request)
    {
        if ($request->has('error')) {
            return redirect()->route('settings.integrations')
                ->with('error', 'X authorization was denied: '.$request->input('error_description'));
        }

        try {
            $redirectUri = route('x.callback');
            $tokenData = $this->xService->exchangeCodeForToken(
                $request->input('code'),
                $redirectUri
            );

            // Create temporary credential to fetch profile
            $tempCredential = new XCredential([
                'access_token' => $tokenData['access_token'],
            ]);

            $profile = $this->xService->getMe($tempCredential);
            $metrics = $profile['public_metrics'] ?? [];

            // Get account type from session (default to personal)
            $accountType = session('x_account_type', XCredential::TYPE_PERSONAL);
            session()->forget('x_account_type');

            // Store credential - unique by user_id + x_user_id
            // Allows same X account connected for both personal and company use
            XCredential::updateOrCreate(
                [
                    'user_id' => auth()->id(),
                    'x_user_id' => $profile['id'],
                ],
                [
                    'username' => $profile['username'],
                    'account_type' => $accountType,
                    'name' => $profile['name'] ?? null,
                    'profile_image_url' => $profile['profile_image_url'] ?? null,
                    'description' => $profile['description'] ?? null,
                    'verified' => $profile['verified'] ?? false,
                    'followers_count' => $metrics['followers_count'] ?? 0,
                    'following_count' => $metrics['following_count'] ?? 0,
                    'tweet_count' => $metrics['tweet_count'] ?? 0,
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'token_expires_at' => now()->addSeconds($tokenData['expires_in']),
                    'scopes' => explode(' ', $tokenData['scope'] ?? ''),
                    'is_active' => true,
                ]
            );

            $typeLabel = $accountType === XCredential::TYPE_COMPANY ? 'company' : 'personal';

            return redirect()->route('settings.integrations')
                ->with('success', "X account @{$profile['username']} connected as {$typeLabel}!");

        } catch (\Exception $e) {
            Log::error('X OAuth failed', ['error' => $e->getMessage()]);

            return redirect()->route('settings.integrations')
                ->with('error', 'Failed to connect X: '.$e->getMessage());
        }
    }

    public function status()
    {
        $credential = auth()->user()->xCredential;

        if (! $credential) {
            return response()->json(['connected' => false]);
        }

        return response()->json([
            'connected' => true,
            'username' => $credential->username,
            'name' => $credential->name,
            'profile_image_url' => $credential->profile_image_url,
            'verified' => $credential->verified,
            'followers_count' => $credential->followers_count,
            'following_count' => $credential->following_count,
            'tweet_count' => $credential->tweet_count,
            'is_active' => $credential->is_active,
            'token_expires_at' => $credential->token_expires_at?->toISOString(),
            'last_synced_at' => $credential->last_synced_at?->toISOString(),
        ]);
    }

    /**
     * Disconnect an X account by ID.
     */
    public function disconnect(Request $request, ?int $id = null)
    {
        $query = auth()->user()->xCredentials();

        if ($id) {
            $credential = $query->find($id);
        } else {
            $credential = $query->first();
        }

        if ($credential) {
            $username = $credential->username;
            $credential->delete();

            return response()->json(['message' => "X account @{$username} disconnected"]);
        }

        return response()->json(['message' => 'X account not found'], 404);
    }

    public function createTweet(Request $request)
    {
        $request->validate([
            'text' => 'required|string|max:280',
            'reply_to' => 'nullable|string',
            'quote_tweet_id' => 'nullable|string',
        ]);

        $credential = auth()->user()->xCredential;

        if (! $credential) {
            return response()->json(['error' => 'X not connected'], 400);
        }

        try {
            $result = $this->xService->createTweet(
                $credential,
                $request->input('text'),
                [
                    'reply_to' => $request->input('reply_to'),
                    'quote_tweet_id' => $request->input('quote_tweet_id'),
                ]
            );

            return response()->json([
                'message' => 'Tweet created successfully',
                'tweet_id' => $result['id'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('X tweet failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to create tweet: '.$e->getMessage()], 500);
        }
    }

    public function createThread(Request $request)
    {
        $request->validate([
            'tweets' => 'required|array|min:2|max:25',
            'tweets.*' => 'required|string|max:280',
        ]);

        $credential = auth()->user()->xCredential;

        if (! $credential) {
            return response()->json(['error' => 'X not connected'], 400);
        }

        try {
            $results = $this->xService->createThread(
                $credential,
                $request->input('tweets')
            );

            return response()->json([
                'message' => 'Thread created successfully',
                'tweets' => $results,
            ]);

        } catch (\Exception $e) {
            Log::error('X thread failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to create thread: '.$e->getMessage()], 500);
        }
    }

    public function getTimeline()
    {
        $credential = auth()->user()->xCredential;

        if (! $credential) {
            return response()->json(['error' => 'X not connected'], 400);
        }

        try {
            $tweets = $this->xService->getUserTimeline($credential, 20);

            return response()->json(['tweets' => $tweets]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function refreshStats()
    {
        $credential = auth()->user()->xCredential;

        if (! $credential) {
            return response()->json(['error' => 'X not connected'], 400);
        }

        try {
            $profile = $this->xService->getMe($credential);
            $metrics = $profile['public_metrics'] ?? [];

            $credential->update([
                'followers_count' => $metrics['followers_count'] ?? 0,
                'following_count' => $metrics['following_count'] ?? 0,
                'tweet_count' => $metrics['tweet_count'] ?? 0,
                'last_synced_at' => now(),
            ]);

            return response()->json([
                'message' => 'Stats refreshed',
                'followers_count' => $credential->followers_count,
                'following_count' => $credential->following_count,
                'tweet_count' => $credential->tweet_count,
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
