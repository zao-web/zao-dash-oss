<?php

namespace App\Jobs;

use App\Mail\ClientReportMail;
use App\Models\Client;
use App\Models\ClientReport;
use App\Services\Reports\ClientReportService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;

class GenerateClientReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public Client $client,
        public Carbon $periodStart,
        public Carbon $periodEnd,
        public string $reportType = 'monthly',
        public ?int $generatedBy = null,
        public bool $sendEmail = false
    ) {}

    public function handle(ClientReportService $reportService): void
    {
        Log::info('Generating client report', [
            'client_id' => $this->client->id,
            'period' => $this->periodStart->format('Y-m').' to '.$this->periodEnd->format('Y-m'),
        ]);

        try {
            // Generate the report data
            $report = $reportService->generateReport(
                $this->client,
                $this->periodStart,
                $this->periodEnd,
                $this->reportType,
                $this->generatedBy
            );

            // Get settings for PDF rendering
            $settings = $reportService->getOrCreateSettings($this->client);

            // Render HTML
            $html = view('reports.client-monthly', [
                'report' => $report,
                'settings' => $settings,
            ])->render();

            // Generate PDF
            $pdfPath = $this->generatePdf($report, $html);

            // Update report with PDF path
            $report->markGenerated($pdfPath, 'local');

            Log::info('Client report generated successfully', [
                'report_id' => $report->id,
                'pdf_path' => $pdfPath,
            ]);

            // Send email if requested
            if ($this->sendEmail) {
                $this->sendReportEmail($report, $settings);
            }
        } catch (\Exception $e) {
            Log::error('Failed to generate client report', [
                'client_id' => $this->client->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // If we have a report record, mark it as failed
            if (isset($report)) {
                $report->markFailed($e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Generate PDF from HTML using Browsershot.
     */
    protected function generatePdf(ClientReport $report, string $html): string
    {
        $filename = sprintf(
            'reports/%s/%s-%s.pdf',
            $report->client_id,
            $report->period_start->format('Y-m'),
            now()->timestamp
        );

        $fullPath = Storage::disk('local')->path($filename);

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Check if Browsershot is available
        if (class_exists(Browsershot::class)) {
            Browsershot::html($html)
                ->format('Letter')
                ->margins(0, 0, 0, 0)
                ->showBackground()
                ->waitUntilNetworkIdle()
                ->save($fullPath);
        } else {
            // Fallback: just save HTML for now
            Log::warning('Browsershot not available, saving HTML instead');
            file_put_contents(str_replace('.pdf', '.html', $fullPath), $html);
            $filename = str_replace('.pdf', '.html', $filename);
        }

        return $filename;
    }

    /**
     * Send the report via email.
     */
    protected function sendReportEmail(ClientReport $report, $settings): void
    {
        $recipients = $settings->recipient_emails;

        if (empty($recipients)) {
            Log::info('No recipients configured for client report', [
                'client_id' => $this->client->id,
            ]);

            return;
        }

        try {
            foreach ($recipients as $email) {
                Mail::to($email)->send(new ClientReportMail($report));
            }

            $report->markSent($recipients);

            Log::info('Client report sent', [
                'report_id' => $report->id,
                'recipients' => $recipients,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send client report email', [
                'report_id' => $report->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create job for previous month.
     */
    public static function forPreviousMonth(
        Client $client,
        ?int $generatedBy = null,
        bool $sendEmail = false
    ): self {
        $start = now()->subMonth()->startOfMonth();
        $end = now()->subMonth()->endOfMonth();

        return new self($client, $start, $end, 'monthly', $generatedBy, $sendEmail);
    }

    /**
     * Create job for previous quarter.
     */
    public static function forPreviousQuarter(
        Client $client,
        ?int $generatedBy = null,
        bool $sendEmail = false
    ): self {
        $start = now()->subQuarter()->startOfQuarter();
        $end = now()->subQuarter()->endOfQuarter();

        return new self($client, $start, $end, 'quarterly', $generatedBy, $sendEmail);
    }
}
