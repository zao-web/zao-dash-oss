<?php

namespace App\Http\Controllers;

use App\Services\GitHub\GitHubOAuthService;
use App\Services\GitHub\GitHubUserApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class GitHubUserController extends Controller
{
    public function __construct(
        private GitHubOAuthService $oauth
    ) {}

    public function status(Request $request)
    {
        $user = $request->user();
        $credential = $user->githubCredential;

        if (! $credential) {
            return response()->json([
                'connected' => false,
            ]);
        }

        return response()->json([
            'connected' => true,
            'username' => $credential->username,
            'email' => $credential->email,
            'avatar_url' => $credential->avatar_url,
            'scopes' => $credential->scopes ?? [],
            'expires_at' => $credential->expires_at?->toIso8601String(),
            'is_expired' => $credential->isExpired(),
        ]);
    }

    public function redirect(Request $request)
    {
        $state = Str::random(40);
        $request->session()->put('github_oauth_state', $state);

        return redirect($this->oauth->getAuthUrl($state));
    }

    public function callback(Request $request)
    {
        $storedState = $request->session()->pull('github_oauth_state');

        if ($request->state !== $storedState) {
            return redirect('/settings/integrations')
                ->with('error', 'Invalid OAuth state. Please try again.');
        }

        if ($request->has('error')) {
            return redirect('/settings/integrations')
                ->with('error', 'GitHub authorization was denied: '.($request->error_description ?? $request->error));
        }

        try {
            $tokens = $this->oauth->exchangeCodeForTokens($request->code);
            $this->oauth->storeCredentials($request->user(), $tokens);

            return redirect('/settings/integrations')
                ->with('success', 'GitHub account connected successfully!');
        } catch (\Exception $e) {
            return redirect('/settings/integrations')
                ->with('error', 'Failed to connect GitHub account: '.$e->getMessage());
        }
    }

    public function disconnect(Request $request)
    {
        $user = $request->user();
        $credential = $user->githubCredential;

        if ($credential) {
            $this->oauth->revokeAccess($credential);
        }

        return response()->json([
            'success' => true,
            'message' => 'GitHub account disconnected.',
        ]);
    }

    public function repositories(Request $request, GitHubUserApiService $api)
    {
        $user = $request->user();

        if (! $this->oauth->hasValidCredentials($user)) {
            return response()->json(['error' => 'GitHub account not connected'], 401);
        }

        try {
            $repos = $api->listUserRepos($user, [
                'per_page' => $request->input('per_page', 100),
                'sort' => $request->input('sort', 'updated'),
            ]);

            return response()->json([
                'repositories' => $repos,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function searchRepositories(Request $request, GitHubUserApiService $api)
    {
        $user = $request->user();

        if (! $this->oauth->hasValidCredentials($user)) {
            return response()->json(['error' => 'GitHub account not connected'], 401);
        }

        $query = $request->input('q', '');
        if (empty($query)) {
            return response()->json(['repositories' => []]);
        }

        try {
            $repos = $api->searchRepos($user, $query);

            return response()->json([
                'repositories' => $repos['items'] ?? [],
                'total_count' => $repos['total_count'] ?? 0,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
