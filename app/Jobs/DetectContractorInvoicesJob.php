<?php

namespace App\Jobs;

use App\Agents\Tools\DetectContractorInvoicesTool;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scan emails from contractors to detect and create invoices.
 *
 * Runs daily after GSuite sync to process new emails.
 */
class DetectContractorInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $backoff = 120;

    public function __construct(
        public int $daysBack = 7,
        public ?int $contractorId = null
    ) {}

    public function handle(): void
    {
        Log::info('Starting contractor invoice detection', [
            'days_back' => $this->daysBack,
            'contractor_id' => $this->contractorId,
        ]);

        $tool = new DetectContractorInvoicesTool;

        $result = $tool->execute([
            'days_back' => $this->daysBack,
            'dry_run' => false,
            'contractor_id' => $this->contractorId,
        ]);

        Log::info('Contractor invoice detection completed', [
            'emails_scanned' => $result['emails_scanned'] ?? 0,
            'detected' => $result['detected_count'] ?? 0,
            'created' => $result['created_count'] ?? 0,
        ]);

        // Notify owner if invoices were detected
        if (! empty($result['created_invoices'])) {
            $this->notifyOwner($result);
        }
    }

    protected function notifyOwner(array $result): void
    {
        $owner = User::where('role', 'owner')->first();

        if (! $owner) {
            return;
        }

        $count = $result['created_count'];
        $totalAmount = array_sum(
            array_column($result['detected_invoices'], 'amount')
        );

        Notification::create([
            'user_id' => $owner->id,
            'type' => 'contractor_invoices_detected',
            'title' => "{$count} Contractor Invoice(s) Detected",
            'message' => sprintf(
                '%d invoice(s) totaling $%s detected from contractor emails and awaiting your approval.',
                $count,
                number_format($totalAmount, 2)
            ),
            'data' => [
                'invoices' => $result['created_invoices'],
                'total_amount' => $totalAmount,
            ],
            'action_url' => '/contractor-invoices/pending',
            'action_label' => 'Review Invoices',
        ]);
    }
}
