<?php

namespace App\Http\Controllers;

use App\Models\GitHubInstallation;
use App\Models\GoogleCredential;
use App\Models\HarvestCredential;
use App\Models\NotionConnection;
use App\Models\PmConnection;
use App\Models\QuickBooksConnection;
use App\Models\SlackWorkspace;
use App\Models\WordPressSite;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IntegrationStatusController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'quickbooks' => $this->getStatus(
                QuickBooksConnection::where('user_id', $user->id)->where('is_active', true)->first()
            ),
            'google' => $this->getStatus(
                GoogleCredential::where('user_id', $user->id)->first()
            ),
            'slack' => $this->getStatus(
                SlackWorkspace::where('is_primary', true)->first()
            ),
            'github' => $this->getStatus(
                GitHubInstallation::first()
            ),
            'harvest' => $this->getStatus(
                HarvestCredential::where('user_id', $user->id)->first()
            ),
            'notion' => $this->getStatus(
                NotionConnection::first()
            ),
            'wordpress' => $this->getStatus(
                WordPressSite::first()
            ),
            'clickup' => $this->getStatus(
                PmConnection::where('user_id', $user->id)->where('provider', 'clickup')->first()
            ),
        ]);
    }

    public function show(Request $request, string $service): JsonResponse
    {
        $user = $request->user();
        $connection = $this->getConnectionForService($service, $user);

        if (! $connection) {
            return response()->json([
                'connected' => false,
                'status' => null,
            ]);
        }

        return response()->json([
            'connected' => true,
            ...$this->getStatus($connection),
        ]);
    }

    protected function getConnectionForService(string $service, $user): ?Model
    {
        return match ($service) {
            'quickbooks' => QuickBooksConnection::where('user_id', $user->id)->where('is_active', true)->first(),
            'google' => GoogleCredential::where('user_id', $user->id)->first(),
            'slack' => SlackWorkspace::where('is_primary', true)->first(),
            'github' => GitHubInstallation::first(),
            'harvest' => HarvestCredential::where('user_id', $user->id)->first(),
            'notion' => NotionConnection::first(),
            'wordpress' => WordPressSite::first(),
            'clickup' => PmConnection::where('user_id', $user->id)->where('provider', 'clickup')->first(),
            default => null,
        };
    }

    protected function getStatus(?Model $connection): ?array
    {
        if (! $connection) {
            return null;
        }

        return [
            'connected' => true,
            'status' => $connection->sync_status ?? 'pending',
            'progress' => $connection->sync_progress ?? 0,
            'started_at' => $connection->sync_started_at?->toIso8601String(),
            'completed_at' => $connection->sync_completed_at?->toIso8601String(),
            'last_synced' => $connection->last_synced_at?->diffForHumans(),
            'error' => $connection->sync_error,
        ];
    }

    /**
     * Check if any integrations are currently syncing.
     */
    public function syncing(Request $request): JsonResponse
    {
        $user = $request->user();

        $syncing = collect([
            'quickbooks' => QuickBooksConnection::where('user_id', $user->id)->where('sync_status', 'syncing')->exists(),
            'google' => GoogleCredential::where('user_id', $user->id)->where('sync_status', 'syncing')->exists(),
            'slack' => SlackWorkspace::where('sync_status', 'syncing')->exists(),
            'github' => GitHubInstallation::where('sync_status', 'syncing')->exists(),
            'harvest' => HarvestCredential::where('user_id', $user->id)->where('sync_status', 'syncing')->exists(),
            'notion' => NotionConnection::where('sync_status', 'syncing')->exists(),
            'wordpress' => WordPressSite::where('sync_status', 'syncing')->exists(),
        ])->filter()->keys()->values();

        return response()->json([
            'any_syncing' => $syncing->isNotEmpty(),
            'services' => $syncing,
        ]);
    }
}
