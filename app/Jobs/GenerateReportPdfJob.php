<?php

namespace App\Jobs;

use App\Mail\ClientReportMail;
use App\Models\ClientReport;
use App\Models\ClientReportSettings;
use App\Services\Pdf\TailwindPdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GenerateReportPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public ClientReport $report,
        public bool $sendAfterGeneration = false,
        public array $recipients = []
    ) {}

    public function handle(): void
    {
        Log::info('Generating PDF for existing report', [
            'report_id' => $this->report->id,
            'client_id' => $this->report->client_id,
        ]);

        try {
            $settings = ClientReportSettings::where('client_id', $this->report->client_id)->first()
                ?? new ClientReportSettings(['client_id' => $this->report->client_id]);

            // Generate PDF using TailwindPdf service
            $pdfPath = $this->generatePdf($settings);

            // Update report with PDF path
            $this->report->markGenerated($pdfPath, 'local');

            Log::info('Report PDF generated successfully', [
                'report_id' => $this->report->id,
                'pdf_path' => $pdfPath,
            ]);

            // Send email if requested
            if ($this->sendAfterGeneration) {
                $this->sendReportEmail($settings);
            }
        } catch (\Exception $e) {
            Log::error('Failed to generate report PDF', [
                'report_id' => $this->report->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->report->markFailed($e->getMessage());

            throw $e;
        }
    }

    protected function generatePdf(ClientReportSettings $settings): string
    {
        $filename = sprintf(
            'reports/%s/%s-%s.pdf',
            $this->report->client_id,
            $this->report->period_start->format('Y-m'),
            now()->timestamp
        );

        // Use TailwindPdf for beautiful, Tailwind-styled PDFs
        TailwindPdf::view('pdf.client-report', [
            'report' => $this->report,
            'settings' => $settings,
        ])
            ->letter()
            ->noMargins()
            ->primaryColor($settings->primary_color ?? '#2563eb')
            ->save($filename);

        return $filename;
    }

    protected function sendReportEmail(ClientReportSettings $settings): void
    {
        $recipients = ! empty($this->recipients) ? $this->recipients : $settings->recipient_emails;

        if (empty($recipients)) {
            Log::info('No recipients configured for client report', [
                'report_id' => $this->report->id,
            ]);

            return;
        }

        try {
            foreach ($recipients as $email) {
                Mail::to($email)->send(new ClientReportMail($this->report));
            }

            $this->report->markSent($recipients);

            Log::info('Client report sent', [
                'report_id' => $this->report->id,
                'recipients' => $recipients,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send client report email', [
                'report_id' => $this->report->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
