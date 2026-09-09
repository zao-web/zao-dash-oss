<?php

namespace App\Http\Controllers;

use App\Models\LinkedInCredential;
use App\Services\LinkedIn\LinkedInService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LinkedInIntegrationController extends Controller
{
    public function __construct(
        protected LinkedInService $linkedInService
    ) {}

    public function redirect()
    {
        $redirectUri = route('linkedin.callback');
        $authUrl = $this->linkedInService->getAuthUrl($redirectUri);

        return redirect($authUrl);
    }

    public function callback(Request $request)
    {
        if ($request->has('error')) {
            return redirect()->route('settings.integrations')
                ->with('error', 'LinkedIn authorization was denied: '.$request->input('error_description'));
        }

        try {
            $redirectUri = route('linkedin.callback');
            $tokenData = $this->linkedInService->exchangeCodeForToken(
                $request->input('code'),
                $redirectUri
            );

            // Create temporary credential to fetch profile
            $tempCredential = new LinkedInCredential([
                'access_token' => $tokenData['access_token'],
            ]);

            $profile = $this->linkedInService->getProfile($tempCredential);

            // Store credential
            LinkedInCredential::updateOrCreate(
                ['user_id' => auth()->id()],
                [
                    'linkedin_id' => $profile['sub'],
                    'name' => $profile['name'] ?? null,
                    'email' => $profile['email'] ?? null,
                    'profile_url' => $profile['profile'] ?? null,
                    'profile_picture' => $profile['picture'] ?? null,
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'token_expires_at' => now()->addSeconds($tokenData['expires_in']),
                    'scopes' => explode(' ', $tokenData['scope'] ?? ''),
                    'is_active' => true,
                ]
            );

            return redirect()->route('settings.integrations')
                ->with('success', 'LinkedIn connected successfully!');

        } catch (\Exception $e) {
            Log::error('LinkedIn OAuth failed', ['error' => $e->getMessage()]);

            return redirect()->route('settings.integrations')
                ->with('error', 'Failed to connect LinkedIn: '.$e->getMessage());
        }
    }

    public function status()
    {
        $credential = auth()->user()->linkedInCredential;

        if (! $credential) {
            return response()->json(['connected' => false]);
        }

        return response()->json([
            'connected' => true,
            'name' => $credential->name,
            'email' => $credential->email,
            'profile_picture' => $credential->profile_picture,
            'organization_name' => $credential->organization_name,
            'is_active' => $credential->is_active,
            'token_expires_at' => $credential->token_expires_at?->toISOString(),
            'last_synced_at' => $credential->last_synced_at?->toISOString(),
        ]);
    }

    public function disconnect()
    {
        $credential = auth()->user()->linkedInCredential;

        if ($credential) {
            $credential->delete();
        }

        return response()->json(['message' => 'LinkedIn disconnected']);
    }

    public function createPost(Request $request)
    {
        $request->validate([
            'text' => 'required|string|max:3000',
            'url' => 'nullable|url',
            'title' => 'required_with:url|string|max:200',
            'description' => 'nullable|string|max:256',
            'as_organization' => 'boolean',
        ]);

        $credential = auth()->user()->linkedInCredential;

        if (! $credential) {
            return response()->json(['error' => 'LinkedIn not connected'], 400);
        }

        try {
            if ($request->boolean('as_organization') && $credential->hasOrganizationAccess()) {
                $result = $this->linkedInService->createOrganizationPost(
                    $credential,
                    $request->input('text')
                );
            } elseif ($request->filled('url')) {
                $result = $this->linkedInService->createArticlePost(
                    $credential,
                    $request->input('text'),
                    $request->input('url'),
                    $request->input('title'),
                    $request->input('description')
                );
            } else {
                $result = $this->linkedInService->createTextPost(
                    $credential,
                    $request->input('text')
                );
            }

            return response()->json([
                'message' => 'Post created successfully',
                'post_id' => $result['id'] ?? null,
            ]);

        } catch (\Exception $e) {
            Log::error('LinkedIn post failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Failed to create post: '.$e->getMessage()], 500);
        }
    }

    public function getOrganizations()
    {
        $credential = auth()->user()->linkedInCredential;

        if (! $credential) {
            return response()->json(['error' => 'LinkedIn not connected'], 400);
        }

        try {
            $organizations = $this->linkedInService->getOrganizations($credential);

            return response()->json([
                'organizations' => $organizations,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function setOrganization(Request $request)
    {
        $request->validate([
            'organization_id' => 'required|string',
            'organization_name' => 'required|string',
        ]);

        $credential = auth()->user()->linkedInCredential;

        if (! $credential) {
            return response()->json(['error' => 'LinkedIn not connected'], 400);
        }

        $credential->update([
            'organization_id' => $request->input('organization_id'),
            'organization_name' => $request->input('organization_name'),
        ]);

        return response()->json(['message' => 'Organization set successfully']);
    }
}
