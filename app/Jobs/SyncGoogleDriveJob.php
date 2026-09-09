<?php

namespace App\Jobs;

use App\Jobs\Concerns\TracksSyncProgress;
use App\Models\Client;
use App\Models\Document;
use App\Models\GoogleCredential;
use App\Models\User;
use App\Services\Google\DriveService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncGoogleDriveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, TracksSyncProgress;

    public function __construct(
        public ?int $userId = null
    ) {}

    public function handle(DriveService $driveService): void
    {
        // Get users with Google credentials
        $users = $this->userId
            ? User::where('id', $this->userId)->get()
            : User::whereHas('googleCredential')->get();

        foreach ($users as $user) {
            $credential = $user->googleCredential;
            if (! $credential) {
                continue;
            }

            try {
                $this->syncForUser($user, $credential, $driveService);
            } catch (\Exception $e) {
                Log::error('Google Drive sync failed for user', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Auto-link documents to clients
        $this->autoLinkDocuments();
    }

    protected function syncForUser(User $user, GoogleCredential $credential, DriveService $driveService): void
    {
        $this->initSyncTracking($credential);

        try {
            Log::info('Syncing Google Drive for user', ['user_id' => $user->id]);
            $this->updateSyncProgress(10, 'discovering');

            $documents = $driveService->discoverDocuments($user);
            $this->updateSyncProgress(40, 'indexing');

            $totalDocs = count($documents);
            foreach ($documents as $index => $file) {
                $driveService->indexDocument($user, $file);
                if ($index % 10 === 0) {
                    $progress = 40 + (int) (($index + 1) / max(1, $totalDocs) * 55);
                    $this->updateSyncProgress($progress, 'indexing documents');
                }
            }

            Log::info('Google Drive sync complete', [
                'user_id' => $user->id,
                'documents_found' => $totalDocs,
            ]);

            $this->completeSyncTracking();
        } catch (\Exception $e) {
            $this->failSyncTracking($e);
            throw $e;
        }
    }

    protected function autoLinkDocuments(): void
    {
        $unlinked = Document::whereNull('client_id')
            ->whereNotNull('extracted_client_name')
            ->get();

        foreach ($unlinked as $doc) {
            // Try fuzzy matching on client name
            $client = Client::where('name', 'LIKE', '%'.$doc->extracted_client_name.'%')
                ->orWhere('name', 'SOUNDS LIKE', $doc->extracted_client_name)
                ->first();

            if ($client) {
                $doc->update(['client_id' => $client->id]);
                Log::info('Auto-linked document to client', [
                    'document_id' => $doc->id,
                    'client_id' => $client->id,
                ]);
            }
        }
    }
}
