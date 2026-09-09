<?php

namespace App\Jobs;

use App\Models\Contractor;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Monitors contractor YTD payments against the $600 1099-NEC threshold.
 * Alerts when contractors cross the threshold without a W-9 on file.
 * Runs monthly on the 15th.
 */
class MonitorContractorThresholdsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $year = now()->year;
        $threshold = 600.00;
        $alerts = 0;

        $contractors = Contractor::active()
            ->usContractors()
            ->get();

        foreach ($contractors as $contractor) {
            $ytdPaid = $contractor->getTotalPaidThisYear();

            if ($ytdPaid >= $threshold && ! $contractor->has_w9_on_file) {
                Notification::create([
                    'user_id' => User::first()?->id,
                    'type' => 'system',
                    'title' => "W-9 needed: {$contractor->name}",
                    'message' => "{$contractor->name} has been paid \$".number_format($ytdPaid, 0).
                        ' this year (over the $600 threshold). A W-9 is required before you can file their 1099-NEC. '.
                        'Request a W-9 from the Tax Office.',
                    'severity' => 'warning',
                    'action_url' => '/life/tax-optimizer',
                    'action_label' => 'View Tax Office',
                    'metadata' => [
                        'contractor_id' => $contractor->id,
                        'ytd_paid' => $ytdPaid,
                    ],
                ]);
                $alerts++;
            }
        }

        if ($alerts > 0) {
            Log::info("MonitorContractorThresholdsJob: {$alerts} contractors need W-9s");
        }
    }
}
