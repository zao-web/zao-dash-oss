<?php

namespace App\Jobs;

use App\Models\NetWorthSnapshot;
use App\Models\User;
use App\Services\PersonalFinance\NorthStarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CaptureNetWorthSnapshotJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function handle(): void
    {
        $user = User::where('role', 'admin')->first();

        if (! $user) {
            Log::warning('CaptureNetWorthSnapshotJob: No admin user found.');

            return;
        }

        NetWorthSnapshot::captureSnapshot($user->id);

        // Capture North Star goal progress alongside net worth
        app(NorthStarService::class)->captureProgress($user->id);

        Log::info('CaptureNetWorthSnapshotJob: Snapshot captured.', [
            'user_id' => $user->id,
        ]);
    }
}
